<?php
// Admin API (single owner account). All actions except login require an admin
// bearer token.
//   POST /admin.php?do=login         -> { token }
//   POST /admin.php?do=logout
//   GET  /admin.php?do=agents        -> all agents (+ referral counts)
//   POST /admin.php?do=agent-status  -> { id, status }  approve / suspend / pending
//   GET  /admin.php?do=requests      -> all project requests (+ agent + commission)
//   POST /admin.php?do=deal          -> { id, deal_amount, deal_status }
//   GET  /admin.php?do=summary       -> totals (requests, revenue, commission, agents)

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = $_GET['do'] ?? '';
$defaultPct = (int) $config['commission_default_pct'];
$maxPct = (int) $config['commission_max_pct'];

try {
    $pdo = db();

    // Login is handled by the unified /login.php (with brute-force throttling).

    if ($method === 'POST' && $do === 'logout') {
        ckx_logout($pdo);
        json_out(['ok' => true]);
    }

    // ---- everything below requires an admin session ----
    ckx_require_admin($pdo);

    // --------------------------------------------------------------- agents
    if ($method === 'GET' && $do === 'agents') {
        $rows = $pdo->query(
            "SELECT a.id, a.name, a.email, a.phone, a.payout_method, a.payout_number,
                    a.ref_token, a.status, a.created_at, a.approved_at,
                    (SELECT COUNT(*) FROM project_requests pr WHERE pr.agent_id = a.id) AS referrals
             FROM agents a
             ORDER BY (a.status = 'pending') DESC, a.created_at DESC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['referrals'] = (int) $r['referrals'];
        }
        unset($r);
        json_out(['agents' => $rows]);
    }

    if ($method === 'POST' && $do === 'agent-status') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $status = field($body, 'status', 20);
        if (!in_array($status, ['pending', 'approved', 'suspended'], true)) {
            json_out(['error' => 'Invalid status.'], 422);
        }
        $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $agent = $stmt->fetch();
        if (!$agent) {
            json_out(['error' => 'Agent not found.'], 404);
        }
        $wasApproved = $agent['status'] === 'approved';
        if ($status === 'approved') {
            $pdo->prepare("UPDATE agents SET status = 'approved', approved_at = COALESCE(approved_at, NOW()) WHERE id = ?")
                ->execute([$id]);
        } else {
            $pdo->prepare("UPDATE agents SET status = ? WHERE id = ?")->execute([$status, $id]);
        }
        $emailed = false;
        if ($status === 'approved' && !$wasApproved) {
            $emailed = send_agent_approved($config['mail'], $config['site_url'], $agent, $maxPct);
        }
        json_out(['ok' => true, 'emailed' => $emailed]);
    }

    // Distinct dates (YYYY-MM-DD, newest first) that have requests — for filters.
    // Optional ?status=lead|won|lost narrows to that deal stage.
    if ($method === 'GET' && $do === 'dates') {
        $status = (string) ($_GET['status'] ?? '');
        if (in_array($status, ['lead', 'won', 'lost'], true)) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT DATE(created_at) d FROM project_requests
                 WHERE deal_status = ? ORDER BY d DESC"
            );
            $stmt->execute([$status]);
            $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $dates = $pdo->query(
                "SELECT DISTINCT DATE(created_at) d FROM project_requests ORDER BY d DESC"
            )->fetchAll(PDO::FETCH_COLUMN);
        }
        json_out(['dates' => $dates]);
    }

    // ------------------------------------------------------------- requests
    if ($method === 'GET' && $do === 'requests') {
        [$cond, $params] = ckx_date_filter(
            (string) ($_GET['month'] ?? ''),
            (string) ($_GET['day'] ?? ''),
            'pr.created_at'
        );
        $status = (string) ($_GET['status'] ?? '');
        if (in_array($status, ['lead', 'won', 'lost'], true)) {
            $cond .= ' AND pr.deal_status = ?';
            $params[] = $status;
        }

        $limit = 10;
        $ct = $pdo->prepare("SELECT COUNT(*) FROM project_requests pr WHERE $cond");
        $ct->execute($params);
        $total = (int) $ct->fetchColumn();
        $pages = max(1, (int) ceil($total / $limit));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $limit; // both ints, safe to inline

        $stmt = $pdo->prepare(
            "SELECT pr.id, pr.reference, pr.path, pr.name, pr.email, pr.phone,
                    pr.business_name, pr.project_title, pr.service, pr.system_type,
                    pr.budget, pr.custom_budget, pr.downpayment, pr.description,
                    pr.deal_amount, pr.deal_status, pr.commission_pct,
                    pr.progress, pr.progress_note, pr.notified_at, pr.paid_at, pr.created_at,
                    pr.agent_id, a.name AS agent_name
             FROM project_requests pr
             LEFT JOIN agents a ON a.id = pr.agent_id
             WHERE $cond
             ORDER BY pr.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Attach up to 5 progress images per request (one extra query, no N+1).
        $imgMap = [];
        $ids = array_column($rows, 'id');
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $im = $pdo->prepare("SELECT id, request_id, path FROM project_images WHERE request_id IN ($in) ORDER BY id");
            $im->execute($ids);
            foreach ($im->fetchAll() as $img) {
                $imgMap[(int) $img['request_id']][] = ['id' => (int) $img['id'], 'url' => $img['path']];
            }
        }

        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['agent_id'] = $r['agent_id'] !== null ? (int) $r['agent_id'] : null;
            $r['deal_amount'] = $r['deal_amount'] !== null ? (float) $r['deal_amount'] : null;
            $r['commission_pct'] = (int) $r['commission_pct'];
            $r['agent_commission'] =
                ($r['deal_status'] === 'won' && $r['agent_id'] && $r['deal_amount'])
                    ? round($r['deal_amount'] * $r['commission_pct'] / 100, 2) : 0.0;
            $r['progress'] = (int) $r['progress'];
            $r['notified'] = $r['notified_at'] !== null;
            $r['paid'] = $r['paid_at'] !== null;
            $r['images'] = $imgMap[$r['id']] ?? [];
        }
        unset($r);
        json_out([
            'requests'    => $rows,
            'default_pct' => $defaultPct,
            'max_pct'     => $maxPct,
            'page'        => $page,
            'pages'       => $pages,
            'total'       => $total,
            'limit'       => $limit,
        ]);
    }

    if ($method === 'POST' && $do === 'deal') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $status = field($body, 'deal_status', 10);
        if (!in_array($status, ['lead', 'won', 'lost'], true)) {
            json_out(['error' => 'Invalid deal status.'], 422);
        }
        $amount = null;
        $raw = $body['deal_amount'] ?? null;
        if ($raw !== null && $raw !== '') {
            if (!is_numeric($raw)) {
                json_out(['error' => 'Amount must be a number.'], 422);
            }
            $amount = (float) $raw;
            if ($amount < 0 || $amount > 99999999) {
                json_out(['error' => 'Amount is out of range.'], 422);
            }
        }
        // Per-client commission percent — default 15, capped at the max (no overlap).
        $pct = isset($body['commission_pct']) ? (int) $body['commission_pct'] : $defaultPct;
        if ($pct < 0) {
            $pct = 0;
        } elseif ($pct > $maxPct) {
            $pct = $maxPct;
        }

        // Owner can finalize/adjust the client's proposed downpayment here.
        $downpayment = field($body, 'downpayment', 120);

        $chk = $pdo->prepare("SELECT 1 FROM project_requests WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_out(['error' => 'Request not found.'], 404);
        }
        $pdo->prepare(
            "UPDATE project_requests
                SET deal_amount = ?, deal_status = ?, commission_pct = ?, downpayment = ?
              WHERE id = ?"
        )->execute([$amount, $status, $pct, $downpayment !== '' ? $downpayment : null, $id]);
        $commission = ($status === 'won' && $amount) ? round($amount * $pct / 100, 2) : 0.0;
        json_out([
            'ok' => true,
            'commission' => $commission,
            'commission_pct' => $pct,
            'downpayment' => $downpayment !== '' ? $downpayment : null,
        ]);
    }

    // ----------------------------------------------------- progress + note
    if ($do === 'progress' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $progress = max(0, min(100, (int) ($body['progress'] ?? 0)));
        $note = mb_substr(clean_text(trim((string) ($body['note'] ?? ''))), 0, 500);

        $chk = $pdo->prepare("SELECT id FROM project_requests WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_out(['error' => 'Request not found.'], 404);
        }
        // Stamp the completion date the first time we hit 100% (clear it if we
        // drop back below) so the heatmap can mark that day green.
        $pdo->prepare(
            "UPDATE project_requests
                SET progress = ?, progress_note = ?,
                    completed_at = CASE WHEN ? >= 100 THEN COALESCE(completed_at, NOW()) ELSE NULL END
              WHERE id = ?"
        )->execute([$progress, $note !== '' ? $note : null, $progress, $id]);
        json_out(['ok' => true, 'progress' => $progress]);
    }

    // -------------------------------------------------------- image upload
    if ($do === 'image-upload' && $method === 'POST') {
        require_allowed_origin($config['allowed_origins']); // multipart body, so not require_json
        $id = (int) ($_POST['id'] ?? 0);

        $chk = $pdo->prepare("SELECT id FROM project_requests WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_out(['error' => 'Request not found.'], 404);
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM project_images WHERE request_id = ?");
        $cnt->execute([$id]);
        if ((int) $cnt->fetchColumn() >= 5) {
            json_out(['error' => 'You can upload up to 5 images per project.'], 422);
        }
        if (!isset($_FILES['image'])) {
            json_out(['error' => 'No image was uploaded.'], 422);
        }
        $path = ckx_save_uploaded_image($_FILES['image'], __DIR__ . '/uploads');
        $pdo->prepare("INSERT INTO project_images (request_id, path) VALUES (?, ?)")
            ->execute([$id, $path]);
        json_out(['ok' => true, 'image' => ['id' => (int) $pdo->lastInsertId(), 'url' => $path]], 201);
    }

    // -------------------------------------------------------- image delete
    if ($do === 'image-delete' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $imgId = (int) ($body['image_id'] ?? 0);
        $row = $pdo->prepare("SELECT path FROM project_images WHERE id = ? LIMIT 1");
        $row->execute([$imgId]);
        $img = $row->fetch();
        if (!$img) {
            json_out(['error' => 'Image not found.'], 404);
        }
        // path is "/uploads/xxx"; this file lives in public/, so __DIR__ . path resolves.
        $onDisk = __DIR__ . $img['path'];
        if (is_file($onDisk)) {
            @unlink($onDisk);
        }
        $pdo->prepare("DELETE FROM project_images WHERE id = ?")->execute([$imgId]);
        json_out(['ok' => true]);
    }

    // --------------------------------------------------- notify client (90%)
    if ($do === 'notify' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM project_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if (!$req) {
            json_out(['error' => 'Request not found.'], 404);
        }
        if ((int) $req['progress'] < 90) {
            json_out(['error' => 'Reach at least 90% before notifying the client.'], 422);
        }
        $emailed = send_progress_notify($config['mail'], $config['site_url'], $req);
        $pdo->prepare("UPDATE project_requests SET notified_at = NOW() WHERE id = ?")->execute([$id]);
        json_out(['ok' => true, 'emailed' => $emailed]);
    }

    // ------------------------------------------- mark completed (+ receipt)
    if ($do === 'complete' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $paid = filter_var($body['paid'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $stmt = $pdo->prepare("SELECT * FROM project_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if (!$req) {
            json_out(['error' => 'Request not found.'], 404);
        }

        // Final price: take an updated amount if sent, else what is on record.
        $raw = $body['deal_amount'] ?? $req['deal_amount'];
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            json_out(['error' => 'Set the final price before completing.'], 422);
        }
        $amount = (float) $raw;
        if ($amount <= 0 || $amount > 99999999) {
            json_out(['error' => 'Please enter a valid final price.'], 422);
        }

        // Completed = won + 100% progress; stamp the completion + payment dates.
        $pdo->prepare(
            "UPDATE project_requests
                SET deal_amount = ?, deal_status = 'won', progress = 100,
                    completed_at = COALESCE(completed_at, NOW()),
                    paid_at = CASE WHEN ? THEN COALESCE(paid_at, NOW()) ELSE paid_at END
              WHERE id = ?"
        )->execute([$amount, $paid ? 1 : 0, $id]);

        // Re-read so the receipt reflects the new amount + paid stamp.
        $stmt->execute([$id]);
        $emailed = send_receipt($config['mail'], $stmt->fetch());

        json_out(['ok' => true, 'emailed' => $emailed]);
    }

    // ------------------------------------------------------- resend receipt
    if ($do === 'resend-receipt' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM project_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch();
        if (!$req) {
            json_out(['error' => 'Request not found.'], 404);
        }
        $emailed = send_receipt($config['mail'], $req);
        json_out(['ok' => true, 'emailed' => $emailed]);
    }

    // -------------------------------------------------------------- summary
    if ($method === 'GET' && $do === 'summary') {
        $a = $pdo->query(
            "SELECT
                COUNT(*) total,
                COALESCE(SUM(deal_status='lead'),0) leads,
                COALESCE(SUM(deal_status='won'),0) won,
                COALESCE(SUM(deal_status='lost'),0) lost,
                COALESCE(SUM(CASE WHEN deal_status='won' THEN deal_amount ELSE 0 END),0) revenue_won,
                COALESCE(SUM(CASE WHEN deal_status='won' AND agent_id IS NOT NULL
                    THEN deal_amount * commission_pct / 100 ELSE 0 END),0) agent_commission
             FROM project_requests"
        )->fetch();
        $ag = $pdo->query(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(status='pending'),0) pending,
                    COALESCE(SUM(status='approved'),0) approved
             FROM agents"
        )->fetch();

        $revenueWon = (float) $a['revenue_won'];
        $agentCommission = round((float) $a['agent_commission'], 2);
        json_out([
            'requests' => [
                'total' => (int) $a['total'],
                'leads' => (int) $a['leads'],
                'won'   => (int) $a['won'],
                'lost'  => (int) $a['lost'],
            ],
            'revenue' => [
                'won_total'        => round($revenueWon, 2),
                'agent_commission' => $agentCommission,
                'owner_net'        => round($revenueWon - $agentCommission, 2),
            ],
            'agents' => [
                'total'    => (int) $ag['total'],
                'pending'  => (int) $ag['pending'],
                'approved' => (int) $ag['approved'],
            ],
            'default_pct' => $defaultPct,
            'max_pct'     => $maxPct,
        ]);
    }

    // --------------------------------------------------------------- settings
    if ($do === 'settings' && $method === 'GET') {
        $ag = $pdo->query(
            "SELECT COUNT(*) total,
                    COALESCE(SUM(status='pending'),0) pending,
                    COALESCE(SUM(status='approved'),0) approved
             FROM agents"
        )->fetch();
        json_out([
            'agent_limit' => (int) ckx_get_setting($pdo, 'agent_limit', '0'),
            'agents' => [
                'total'    => (int) $ag['total'],
                'pending'  => (int) $ag['pending'],
                'approved' => (int) $ag['approved'],
            ],
            'commission' => [
                'default_pct' => $defaultPct,
                'max_pct'     => $maxPct,
            ],
        ]);
    }

    if ($do === 'settings' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $limit = (int) ($body['agent_limit'] ?? 0);
        if ($limit < 0) {
            $limit = 0;
        } elseif ($limit > 100000) {
            $limit = 100000;
        }
        ckx_set_setting($pdo, 'agent_limit', (string) $limit);
        json_out(['ok' => true, 'agent_limit' => $limit]);
    }

    // ---------------------------------------------------------------- account
    if ($do === 'account' && $method === 'GET') {
        $admin = ckx_admin_account($pdo, $config['admin']);
        json_out(['name' => $admin['name'], 'email' => $admin['email']]);
    }

    if ($do === 'account' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $admin = ckx_admin_account($pdo, $config['admin']);

        $current = (string) ($body['current_password'] ?? '');
        if ($admin['password_hash'] === '' || !password_verify($current, $admin['password_hash'])) {
            json_out(['errors' => ['current_password' => 'Current password is incorrect.']], 403);
        }

        $name = field($body, 'name', 120);
        $email = field($body, 'email', 160);
        $newPass = (string) ($body['new_password'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Please enter a name.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email.';
        }
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

        ckx_set_setting($pdo, 'admin_name', $name);
        ckx_set_setting($pdo, 'admin_email', $email);
        if ($newPass !== '') {
            ckx_set_setting($pdo, 'admin_password_hash', password_hash($newPass, PASSWORD_DEFAULT));
            // Kill every other admin session so a leaked/stolen token can't
            // outlive this password change. The session making this request
            // stays signed in.
            ckx_revoke_other_sessions($pdo, 'admin', 0);
        }
        json_out(['ok' => true, 'name' => $name, 'email' => $email]);
    }

    // ------------------------------------------------------------------ works
    // Portfolio items shown in the "Selected Work" section on the landing
    // page. The admin pastes a link, names it, and uploads a screenshot —
    // there's no in-house way to render another site's page on shared
    // hosting, so the image is admin-supplied rather than auto-captured.
    if ($do === 'works' && $method === 'GET') {
        $rows = $pdo->query(
            "SELECT id, name, url, category, description, image, featured, enabled, created_at
               FROM works ORDER BY id DESC"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['featured'] = (bool) $r['featured'];
            $r['enabled'] = (bool) $r['enabled'];
            $r['domain'] = preg_replace('/^www\./i', '', (string) (parse_url($r['url'], PHP_URL_HOST) ?: ''));
        }
        unset($r);
        json_out(['works' => $rows]);
    }

    if ($do === 'work-create' && $method === 'POST') {
        require_allowed_origin($config['allowed_origins']); // multipart body, so not require_json
        $name = field($_POST, 'name', 160);
        $url = field($_POST, 'url', 500);
        $category = field($_POST, 'category', 160);
        $description = mb_substr(clean_text(trim((string) ($_POST['description'] ?? ''))), 0, 1000);

        $errors = [];
        $set = function (string $k, ?string $m) use (&$errors) {
            if ($m !== null) $errors[$k] = $m;
        };
        $set('name', ckx_validate_text($name, 'a project name', 2, 160));
        $set('url', ckx_validate_url($url));
        if ($category !== '') {
            $set('category', ckx_validate_text($category, 'a category', 2, 160));
        }
        if (!isset($_FILES['image'])) {
            $errors['image'] = 'Please upload a screenshot of the site.';
        }
        if ($errors) {
            json_out(['errors' => $errors], 422);
        }

        $path = ckx_save_uploaded_image($_FILES['image'], __DIR__ . '/uploads');

        $pdo->prepare(
            "INSERT INTO works (name, url, category, description, image) VALUES (?, ?, ?, ?, ?)"
        )->execute([$name, $url, $category ?: null, $description ?: null, $path]);

        json_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($do === 'work-update' && $method === 'POST') {
        require_allowed_origin($config['allowed_origins']); // multipart body, so not require_json
        $id = (int) ($_POST['id'] ?? 0);
        $name = field($_POST, 'name', 160);
        $url = field($_POST, 'url', 500);
        $category = field($_POST, 'category', 160);
        $description = mb_substr(clean_text(trim((string) ($_POST['description'] ?? ''))), 0, 1000);

        $chk = $pdo->prepare("SELECT image FROM works WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        $existing = $chk->fetch();
        if (!$existing) {
            json_out(['error' => 'Project not found.'], 404);
        }

        $errors = [];
        $set = function (string $k, ?string $m) use (&$errors) {
            if ($m !== null) $errors[$k] = $m;
        };
        $set('name', ckx_validate_text($name, 'a project name', 2, 160));
        $set('url', ckx_validate_url($url));
        if ($category !== '') {
            $set('category', ckx_validate_text($category, 'a category', 2, 160));
        }
        if ($errors) {
            json_out(['errors' => $errors], 422);
        }

        // A new image replaces the old one; otherwise keep what's on file.
        $path = $existing['image'];
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $path = ckx_save_uploaded_image($_FILES['image'], __DIR__ . '/uploads');
            $old = __DIR__ . $existing['image'];
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $pdo->prepare(
            "UPDATE works SET name = ?, url = ?, category = ?, description = ?, image = ? WHERE id = ?"
        )->execute([$name, $url, $category ?: null, $description ?: null, $path, $id]);

        json_out(['ok' => true]);
    }

    if ($do === 'work-toggle' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $chk = $pdo->prepare("SELECT 1 FROM works WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_out(['error' => 'Project not found.'], 404);
        }
        $pdo->prepare("UPDATE works SET enabled = ? WHERE id = ?")->execute([$enabled ? 1 : 0, $id]);
        json_out(['ok' => true, 'enabled' => $enabled]);
    }

    if ($do === 'work-feature' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $featured = filter_var($body['featured'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $chk = $pdo->prepare("SELECT 1 FROM works WHERE id = ? LIMIT 1");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            json_out(['error' => 'Project not found.'], 404);
        }
        if ($featured) {
            // Only one project holds the hero slot at a time.
            $pdo->exec("UPDATE works SET featured = 0");
        }
        $pdo->prepare("UPDATE works SET featured = ? WHERE id = ?")->execute([$featured ? 1 : 0, $id]);
        json_out(['ok' => true, 'featured' => $featured]);
    }

    if ($do === 'work-delete' && $method === 'POST') {
        require_json();
        require_allowed_origin($config['allowed_origins']);
        $body = read_json_body();
        $id = (int) ($body['id'] ?? 0);
        $row = $pdo->prepare("SELECT image FROM works WHERE id = ? LIMIT 1");
        $row->execute([$id]);
        $work = $row->fetch();
        if (!$work) {
            json_out(['error' => 'Project not found.'], 404);
        }
        $onDisk = __DIR__ . $work['image'];
        if (is_file($onDisk)) {
            @unlink($onDisk);
        }
        $pdo->prepare("DELETE FROM works WHERE id = ?")->execute([$id]);
        json_out(['ok' => true]);
    }

    json_out(['error' => 'Not found.'], 404);
} catch (Throwable $e) {
    error_log('CKX admin error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
