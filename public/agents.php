<?php
// Agents API
//   POST /agents.php?do=apply    -> register an agent (status: pending)
//   POST /agents.php?do=login    -> log in (must be approved) -> { token, agent }
//   POST /agents.php?do=logout   -> end the current session
//   GET  /agents.php?do=me       -> profile + referred clients + earnings (auth)

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

const PAYOUT_METHODS = ['GCash', 'Maya', 'Bank', 'Other'];
const AGENT_SIGNUP_MAX_PER_IP = 2; // max applications from one IP per 24h

function agent_public(array $a): array
{
    return [
        'id'            => (int) $a['id'],
        'name'          => $a['name'],
        'email'         => $a['email'],
        'phone'         => $a['phone'],
        'payout_method' => $a['payout_method'],
        'payout_number' => $a['payout_number'],
        'ref_token'     => $a['ref_token'],
        'status'        => $a['status'],
        'created_at'    => $a['created_at'],
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = $_GET['do'] ?? '';

try {
    $pdo = db();

    // ---------------------------------------------------------------- apply
    if ($method === 'POST' && $do === 'apply') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();

        if (trim((string) ($body['hp'] ?? '')) !== '') {
            json_out(['ok' => true], 200); // honeypot
        }

        $ipHash = hash('sha256', client_ip() . '|' . $config['ip_salt']);

        $name     = field($body, 'name', 120);
        $email    = field($body, 'email', 160);
        $phone    = field($body, 'phone', 60);
        $password = (string) ($body['password'] ?? '');
        $payoutMethod = field($body, 'payoutMethod', 40);
        $payoutNumber = field($body, 'payoutNumber', 120);

        $errors = [];
        $set = function (string $k, ?string $m) use (&$errors) {
            if ($m !== null) $errors[$k] = $m;
        };
        $set('name', ckx_validate_name($name));
        $set('email', ckx_validate_email($email));
        $set('phone', ckx_validate_contact($phone));
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 200) {
            $errors['password'] = 'Password is too long.';
        }
        if (!in_array($payoutMethod, PAYOUT_METHODS, true)) {
            $errors['payoutMethod'] = 'Please choose a payout method.';
        }
        if ($payoutNumber === '') {
            $errors['payoutNumber'] = 'Please enter your payout number/account.';
        }
        if ($errors) {
            json_out(['errors' => $errors], 422);
        }

        // Email must be unique, and distinct from the owner/admin account.
        $exists = $pdo->prepare("SELECT 1 FROM agents WHERE email = ? LIMIT 1");
        $exists->execute([$email]);
        if ($exists->fetch()) {
            json_out(['errors' => ['email' => 'An account with this email already exists.']], 409);
        }
        $admin = ckx_admin_account($pdo, $config['admin']);
        if ($admin['email'] !== '' && strtolower($admin['email']) === strtolower($email)) {
            json_out(['errors' => ['email' => 'An account with this email already exists.']], 409);
        }

        // Capacity limit set by the admin in Settings (0 = unlimited).
        $agentLimit = (int) ckx_get_setting($pdo, 'agent_limit', '0');
        if ($agentLimit > 0) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM agents")->fetchColumn();
            if ($count >= $agentLimit) {
                json_out(['error' => 'Agent applications are currently closed.'], 403);
            }
        }

        // Per-IP signup limit (anti-spam): cap applications from one network per day.
        $sc = $pdo->prepare(
            "SELECT COUNT(*) FROM agents WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL 1 DAY)"
        );
        $sc->execute([$ipHash]);
        if ((int) $sc->fetchColumn() >= AGENT_SIGNUP_MAX_PER_IP) {
            json_out(['error' => 'Too many applications from your network. Please try again later or contact us directly.'], 429);
        }

        $token = ckx_make_ref_token($pdo);
        $pdo->prepare(
            "INSERT INTO agents (name, email, phone, password_hash, payout_method, payout_number, ref_token, status, ip_hash)
             VALUES (?,?,?,?,?,?,?, 'pending', ?)"
        )->execute([
            $name,
            $email,
            $phone ?: null,
            password_hash($password, PASSWORD_DEFAULT),
            $payoutMethod,
            $payoutNumber,
            $token,
            $ipHash,
        ]);

        json_out([
            'ok' => true,
            'message' => 'Application received. We will review it and email you once approved.',
        ], 201);
    }

    // Login is handled by the unified /login.php (with brute-force throttling).

    // --------------------------------------------------------------- logout
    if ($method === 'POST' && $do === 'logout') {
        ckx_logout($pdo);
        json_out(['ok' => true]);
    }

    // ------------------------------------------------------------------- me
    // Profile + totals (computed across ALL referrals). The client list itself
    // is fetched separately and paginated via ?do=clients.
    if ($method === 'GET' && $do === 'me') {
        $agent = ckx_require_agent($pdo);

        $agg = $pdo->prepare(
            "SELECT COUNT(*) referrals,
                    COALESCE(SUM(deal_status='won'),0) won,
                    COALESCE(SUM(deal_status='lead'),0) pending,
                    COALESCE(SUM(CASE WHEN deal_status='won'
                        THEN deal_amount * commission_pct / 100 ELSE 0 END),0) earnings
             FROM project_requests WHERE agent_id = ?"
        );
        $agg->execute([(int) $agent['id']]);
        $s = $agg->fetch();

        json_out([
            'agent' => agent_public($agent),
            'stats' => [
                'referrals' => (int) $s['referrals'],
                'won'       => (int) $s['won'],
                'pending'   => (int) $s['pending'],
                'earnings'  => round((float) $s['earnings'], 2),
            ],
        ]);
    }

    // Distinct dates (newest first) that this agent has referrals on — for filters.
    if ($method === 'GET' && $do === 'dates') {
        $agent = ckx_require_agent($pdo);
        $stmt = $pdo->prepare(
            "SELECT DISTINCT DATE(created_at) d FROM project_requests
             WHERE agent_id = ? ORDER BY d DESC"
        );
        $stmt->execute([(int) $agent['id']]);
        json_out(['dates' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
    }

    // --------------------------------------------------------- clients (page)
    if ($method === 'GET' && $do === 'clients') {
        $agent = ckx_require_agent($pdo);
        $aid = (int) $agent['id'];

        [$cond, $dp] = ckx_date_filter(
            (string) ($_GET['month'] ?? ''),
            (string) ($_GET['day'] ?? '')
        );
        $where = "agent_id = ? AND $cond";
        $params = array_merge([$aid], $dp);

        // Optional deal-stage filter (pending = lead, completed = won).
        $status = (string) ($_GET['status'] ?? '');
        if (in_array($status, ['lead', 'won', 'lost'], true)) {
            $where .= ' AND deal_status = ?';
            $params[] = $status;
        }

        $limit = 10;
        $ct = $pdo->prepare("SELECT COUNT(*) FROM project_requests WHERE $where");
        $ct->execute($params);
        $total = (int) $ct->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $limit; // both ints, safe to inline

        $stmt = $pdo->prepare(
            "SELECT reference, name, business_name, project_title, path,
                    deal_amount, downpayment, deal_status, commission_pct, created_at
             FROM project_requests WHERE $where
             ORDER BY id DESC LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $clients = $stmt->fetchAll();
        foreach ($clients as &$c) {
            $c['deal_amount'] = $c['deal_amount'] !== null ? (float) $c['deal_amount'] : null;
            $c['commission_pct'] = (int) $c['commission_pct'];
            $c['commission'] = ($c['deal_status'] === 'won' && $c['deal_amount'])
                ? round($c['deal_amount'] * $c['commission_pct'] / 100, 2) : 0.0;
        }
        unset($c);

        json_out([
            'clients'  => $clients,
            'page'     => $page,
            'pages'    => $pages,
            'total'    => $total,
            'has_more' => $page < $pages,
        ]);
    }

    // ----------------------------------------------------- account (auth) update
    if ($method === 'POST' && $do === 'account') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $agent = ckx_require_agent($pdo);
        $body = read_json_body();

        $current = (string) ($body['current_password'] ?? '');
        if (!password_verify($current, $agent['password_hash'])) {
            json_out(['errors' => ['current_password' => 'Current password is incorrect.']], 403);
        }

        $name = field($body, 'name', 120);
        $email = field($body, 'email', 160);
        $newPass = (string) ($body['new_password'] ?? '');

        $errors = [];
        $set = function (string $k, ?string $m) use (&$errors) {
            if ($m !== null) $errors[$k] = $m;
        };
        $set('name', ckx_validate_name($name));
        $set('email', ckx_validate_email($email));
        if ($newPass !== '') {
            if (strlen($newPass) < 8) {
                $errors['new_password'] = 'New password must be at least 8 characters.';
            } elseif (strlen($newPass) > 200) {
                $errors['new_password'] = 'New password is too long.';
            }
        }
        if ($errors) {
            json_out(['errors' => $errors], 422);
        }

        $ex = $pdo->prepare("SELECT 1 FROM agents WHERE email = ? AND id <> ? LIMIT 1");
        $ex->execute([$email, (int) $agent['id']]);
        if ($ex->fetch()) {
            json_out(['errors' => ['email' => 'That email is already in use.']], 409);
        }
        $admin = ckx_admin_account($pdo, $config['admin']);
        if ($admin['email'] !== '' && strtolower($admin['email']) === strtolower($email)) {
            json_out(['errors' => ['email' => 'That email is already in use.']], 409);
        }

        if ($newPass !== '') {
            $pdo->prepare("UPDATE agents SET name = ?, email = ?, password_hash = ? WHERE id = ?")
                ->execute([$name, $email, password_hash($newPass, PASSWORD_DEFAULT), (int) $agent['id']]);
            // Kill every other session for this agent so a leaked/stolen
            // token can't outlive this password change. This session stays
            // signed in.
            ckx_revoke_other_sessions($pdo, 'agent', (int) $agent['id']);
        } else {
            $pdo->prepare("UPDATE agents SET name = ?, email = ? WHERE id = ?")
                ->execute([$name, $email, (int) $agent['id']]);
        }
        json_out(['ok' => true, 'name' => $name, 'email' => $email]);
    }

    json_out(['error' => 'Not found.'], 404);
} catch (Throwable $e) {
    error_log('CKX agents error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
