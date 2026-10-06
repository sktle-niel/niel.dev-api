<?php
// Project requests API
//   POST /requests.php  -> create a project request (JSON body) from the
//                          website's "Start a project" form. The record is
//                          saved to MySQL, then an email is sent to the owner.
//
// Anti-spam: hidden honeypot field ("hp") + per-IP rate limiting. Visitor IPs
// are stored only as a salted hash.

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    json_out(['error' => 'Method not allowed. Use POST.'], 405);
}

// CSRF hardening: only our own site (allowed Origin) sending JSON may post.
require_allowed_origin($config['allowed_origins']);
require_json();

// --- Limits & allowed values ---
const RL_MAX_PER_HOUR = 8;   // max submissions per IP per hour
const RL_MIN_SECONDS  = 20;  // minimum gap between submissions from one IP

const SYSTEM_TYPES = ['website', 'desktop', 'mobile'];
const SERVICES     = ['business-website', 'landing-page', 'web-system', 'inventory', 'booking', 'other'];
const BUDGETS      = ['3-5k', '5-10k', '10-20k', '20-50k', 'custom'];

// Public reference, e.g. "CKX-7G2K9Q".
function make_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no easily-confused chars
    $n = strlen($alphabet); // derive from the alphabet, don't hardcode it
    $out = '';
    $bytes = random_bytes(6);
    for ($i = 0; $i < 6; $i++) {
        $out .= $alphabet[ord($bytes[$i]) % $n];
    }
    return 'CKX-' . $out;
}

try {
    $body = read_json_body();

    // Honeypot: a hidden field real users never fill. If present, treat as spam
    // but answer 200 so bots don't learn anything.
    if (trim((string) ($body['hp'] ?? '')) !== '') {
        json_out(['ok' => true, 'reference' => make_reference(), 'emailed' => false], 200);
    }

    $path        = field($body, 'path', 20);
    $systemType  = field($body, 'systemType', 40);
    $service     = field($body, 'service', 40);
    $projectTitle = field($body, 'projectTitle', 160);
    $businessName = field($body, 'businessName', 160);
    $industry    = field($body, 'industry', 120);
    $hasExisting = field($body, 'hasExisting', 10);
    $description = mb_substr(clean_text(trim((string) ($body['description'] ?? ''))), 0, 4000);
    $deadline    = field($body, 'deadline', 120);
    $budget      = field($body, 'budget', 40);
    $customBudget = field($body, 'customBudget', 120);
    $downpayment = field($body, 'downpayment', 120); // optional; client can finalize with owner later
    $name        = field($body, 'name', 120);
    $email       = field($body, 'email', 160);
    $phone       = field($body, 'phone', 60);
    $org         = field($body, 'org', 160);
    $ref         = field($body, 'ref', 20); // referral token from an agent's link

    // --- Validation (server is the source of truth; mirrors the frontend) ---
    $errors = [];
    $set = function (string $k, ?string $msg) use (&$errors): void {
        if ($msg !== null) {
            $errors[$k] = $msg;
        }
    };

    $isCapstone = ($path === 'capstone');
    if ($path !== 'capstone' && $path !== 'business') {
        $errors['path'] = 'Please choose what this project is for.';
    }

    if ($isCapstone) {
        if (!in_array($systemType, SYSTEM_TYPES, true)) {
            $errors['systemType'] = 'Please choose a system type.';
        }
        $set('projectTitle', ckx_validate_text($projectTitle, 'your project title', 3));
    } elseif ($path === 'business') {
        if (!in_array($service, SERVICES, true)) {
            $errors['service'] = 'Please choose what you need.';
        }
        $set('businessName', ckx_validate_text($businessName, 'your business name', 2));
        if ($industry !== '') {
            $set('industry', ckx_validate_text($industry, 'industry', 2));
        }
    }

    $set('description', ckx_validate_description($description));

    if (!in_array($budget, BUDGETS, true)) {
        $errors['budget'] = 'Please select a budget.';
    } elseif ($budget === 'custom') {
        $set('customBudget', ckx_validate_custom_budget($customBudget));
    }
    $set('downpayment', ckx_validate_downpayment($downpayment));

    $set('name', ckx_validate_name($name));
    $set('email', ckx_validate_email($email));
    $set('phone', ckx_validate_contact($phone));
    if ($org !== '') {
        $set('org', ckx_validate_text($org, $isCapstone ? 'school' : 'company', 2));
    }

    if ($errors) {
        json_out(['errors' => $errors], 422);
    }

    $pdo = db();

    // --- Referral attribution: link to an approved agent if a valid token came in.
    // Wrapped so a missing agents table (pre-migration) never breaks submissions. ---
    $agentId  = null;
    $refToken = null;
    if ($ref !== '' && preg_match('/^[A-Za-z0-9]+$/', $ref)) {
        try {
            $a = $pdo->prepare("SELECT id FROM agents WHERE ref_token = ? AND status = 'approved' LIMIT 1");
            $a->execute([$ref]);
            if ($row = $a->fetch()) {
                $agentId  = (int) $row['id'];
                $refToken = $ref;
            }
        } catch (Throwable $e) {
            // agents table not present yet — ignore the referral.
        }
    }

    // --- Per-IP rate limiting (IP stored only as a salted hash) ---
    $ipHash = hash('sha256', client_ip() . '|' . $config['ip_salt']);
    $rl = $pdo->prepare(
        "SELECT
            COALESCE(SUM(created_at > (NOW() - INTERVAL 1 HOUR)), 0) AS hourly,
            COALESCE(SUM(created_at > (NOW() - INTERVAL " . RL_MIN_SECONDS . " SECOND)), 0) AS recent
         FROM project_requests WHERE ip_hash = ?"
    );
    $rl->execute([$ipHash]);
    $counts = $rl->fetch();
    if ((int) $counts['recent'] > 0) {
        json_out(['error' => 'Please wait a moment before sending another request.'], 429);
    }
    if ((int) $counts['hourly'] >= RL_MAX_PER_HOUR) {
        json_out(['error' => 'You have sent several requests already. Please try again later.'], 429);
    }

    // --- Insert (retry once on the very unlikely reference collision) ---
    $reference = make_reference();
    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    for ($try = 0; ; $try++) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO project_requests
                    (reference, path, system_type, service, project_title, business_name,
                     industry, has_existing, description, deadline, budget, custom_budget,
                     downpayment, name, email, phone, org, agent_id, ref_token, ip_hash, user_agent)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $reference,
                $path,
                $isCapstone ? ($systemType ?: null) : null,
                $path === 'business' ? ($service ?: null) : null,
                $projectTitle ?: null,
                $businessName ?: null,
                $industry ?: null,
                $hasExisting ?: null,
                $description,
                $deadline ?: null,
                $budget ?: null,
                $budget === 'custom' ? ($customBudget ?: null) : null,
                $downpayment ?: null,
                $name,
                $email,
                $phone ?: null,
                $org ?: null,
                $agentId,
                $refToken,
                $ipHash,
                $ua,
            ]);
            break;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' && $try < 3) {
                $reference = make_reference();
                continue;
            }
            throw $e;
        }
    }

    // --- Notify (best-effort; data is already saved) ---
    $payload = [
        'reference'     => $reference,
        'path'          => $path,
        'system_type'   => $systemType,
        'service'       => $service,
        'project_title' => $projectTitle,
        'business_name' => $businessName,
        'industry'      => $industry,
        'has_existing'  => $hasExisting,
        'description'   => $description,
        'deadline'      => $deadline,
        'budget'        => $budget,
        'custom_budget' => $customBudget,
        'downpayment'   => $downpayment,
        'name'          => $name,
        'email'         => $email,
        'phone'         => $phone,
        'org'           => $org,
    ];
    $emailed = send_request_email($config['mail'], $payload);   // -> owner inbox
    send_client_confirmation($config['mail'], $payload);        // -> client's email

    json_out(['ok' => true, 'reference' => $reference, 'emailed' => $emailed], 201);
} catch (Throwable $e) {
    error_log('CKX requests error: ' . $e->getMessage());
    json_out(['error' => 'Could not save your request. Please try again shortly.'], 500);
}
