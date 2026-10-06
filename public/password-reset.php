<?php
// Password reset for both the admin and agents. Two steps, both answering
// with generic messages so this flow can never be used to enumerate accounts.
//   POST /password-reset.php?do=request { email }            -> always { ok: true }
//   POST /password-reset.php?do=confirm { token, password }  -> { ok: true } | error

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_out(['error' => 'Method not allowed. Use POST.'], 405);
}
require_json();
require_allowed_origin($config['allowed_origins']);

$do = $_GET['do'] ?? '';

try {
    $pdo = db();
    $body = read_json_body();

    // --------------------------------------------------------------- request
    if ($do === 'request') {
        $email = field($body, 'email', 160);

        // The abuse vector here is spamming someone's inbox, not password
        // guessing, so this only needs to cap send volume per IP.
        $ipHash = hash('sha256', client_ip() . '|reset-request|' . $config['ip_salt']);
        ckx_rate_limit($pdo, 'reset-request', $ipHash, 5, 900); // 5 / 15 min / IP (fails open)

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $admin = ckx_admin_account($pdo, $config['admin']);
            if ($admin['password_hash'] !== '' && strtolower($admin['email']) === strtolower($email)) {
                $token = ckx_issue_reset_token($pdo, 'admin', 0);
                send_password_reset($config['mail'], $config['site_url'], $admin['email'], $admin['name'], $token);
            } else {
                $stmt = $pdo->prepare("SELECT id, name, email, status FROM agents WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
                $agent = $stmt->fetch();
                if ($agent && $agent['status'] === 'approved') {
                    $token = ckx_issue_reset_token($pdo, 'agent', (int) $agent['id']);
                    send_password_reset($config['mail'], $config['site_url'], $agent['email'], $agent['name'], $token);
                }
            }
        }

        // Same response whether or not the email matched anything.
        json_out(['ok' => true, 'message' => 'If that email has an account, a reset link is on its way.']);
    }

    // --------------------------------------------------------------- confirm
    if ($do === 'confirm') {
        $token = (string) ($body['token'] ?? '');
        $newPass = (string) ($body['password'] ?? '');

        if ($token === '') {
            json_out(['error' => 'Invalid or expired reset link.'], 422);
        }
        if (strlen($newPass) < 8) {
            json_out(['errors' => ['password' => 'Password must be at least 8 characters.']], 422);
        } elseif (strlen($newPass) > 200) {
            json_out(['errors' => ['password' => 'Password is too long.']], 422);
        }

        $ipHash = hash('sha256', client_ip() . '|reset-confirm|' . $config['ip_salt']);
        if (!ckx_rate_limit($pdo, 'reset-confirm', $ipHash, 20, 900)) { // 20 / 15 min / IP
            json_out(['error' => 'Too many attempts. Please try again later.'], 429);
        }

        $claim = ckx_consume_reset_token($pdo, $token);
        if (!$claim) {
            json_out(['error' => 'Invalid or expired reset link.'], 422);
        }

        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        if ($claim['user_type'] === 'admin') {
            ckx_set_setting($pdo, 'admin_password_hash', $hash);
            // Reset implies "the previous credential may be compromised" —
            // clear every session, including any the requester currently holds.
            $pdo->prepare("DELETE FROM sessions WHERE user_type = 'admin'")->execute();
        } else {
            $pdo->prepare("UPDATE agents SET password_hash = ? WHERE id = ?")
                ->execute([$hash, (int) $claim['user_id']]);
            $pdo->prepare("DELETE FROM sessions WHERE user_type = 'agent' AND user_id = ?")
                ->execute([(int) $claim['user_id']]);
        }

        json_out(['ok' => true, 'message' => 'Password updated. Please log in.']);
    }

    json_out(['error' => 'Not found.'], 404);
} catch (Throwable $e) {
    error_log('CKX password-reset error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong. Please try again.'], 500);
}
