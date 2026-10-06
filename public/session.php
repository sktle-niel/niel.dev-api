<?php
// Lightweight "who am I" check. Validates the bearer token (and slides its
// expiry) so the client can skip the login form when a session is still alive
// and route the user straight to the right dashboard.
//   GET /session.php  ->  { role: 'admin'|'agent', name, email }  | 401/403

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

try {
    $pdo = db();
    $s = ckx_current_session($pdo); // also slides the session's expiry
    if (!$s) {
        json_out(['error' => 'Not authenticated.'], 401);
    }

    if ($s['user_type'] === 'admin') {
        $admin = ckx_admin_account($pdo, $config['admin']);
        json_out(['role' => 'admin', 'name' => $admin['name'], 'email' => $admin['email']]);
    }

    // Agent — re-check the live status so a suspended/removed account can't keep
    // riding an old token.
    $stmt = $pdo->prepare("SELECT name, email, status FROM agents WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $s['user_id']]);
    $agent = $stmt->fetch();
    if (!$agent || $agent['status'] !== 'approved') {
        json_out(['error' => 'This account is not active.'], 403);
    }
    json_out(['role' => 'agent', 'name' => $agent['name'], 'email' => $agent['email']]);
} catch (Throwable $e) {
    error_log('CKX session error: ' . $e->getMessage());
    json_out(['error' => 'Something went wrong.'], 500);
}
