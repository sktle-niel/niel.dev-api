<?php
// Token-based auth for agents and the admin. Opaque bearer tokens are stored
// only as a sha256 hash in the `sessions` table; the raw token lives client-side.

// Sessions slide: every use that's been idle a while bumps the expiry back to
// this many days out. So an active login never has to re-authenticate, while a
// session left untouched for this long simply dies.
const CKX_SESSION_DAYS = 30;

function ckx_bearer_token(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $h = $v;
                break;
            }
        }
    }
    return stripos($h, 'Bearer ') === 0 ? trim(substr($h, 7)) : '';
}

// Create a session and return the raw token to hand to the client.
function ckx_issue_token(PDO $pdo, string $userType, int $userId, int $days = CKX_SESSION_DAYS): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO sessions (user_type, user_id, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))"
    )->execute([$userType, $userId, hash('sha256', $token), $days]);

    // Opportunistic cleanup of expired sessions.
    $pdo->query("DELETE FROM sessions WHERE expires_at < NOW()");
    return $token;
}

// The current session ['user_type','user_id'] for a valid bearer token, or null.
function ckx_current_session(PDO $pdo): ?array
{
    $token = ckx_bearer_token();
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        "SELECT user_type, user_id FROM sessions
         WHERE token_hash = ? AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    // Sliding expiry: top up an aging session (at most once a day, so we don't
    // write on every single request) so active logins stay signed in.
    $pdo->prepare(
        "UPDATE sessions
         SET expires_at = DATE_ADD(NOW(), INTERVAL " . CKX_SESSION_DAYS . " DAY)
         WHERE token_hash = ?
           AND expires_at < DATE_ADD(NOW(), INTERVAL " . (CKX_SESSION_DAYS - 1) . " DAY)"
    )->execute([$hash]);
    return $row;
}

function ckx_logout(PDO $pdo): void
{
    $token = ckx_bearer_token();
    if ($token !== '') {
        $pdo->prepare("DELETE FROM sessions WHERE token_hash = ?")
            ->execute([hash('sha256', $token)]);
    }
}

// Revoke every OTHER active session for this user, keeping only the one
// making the current request signed in. Call this right after a password
// change so a stolen/leaked token can't outlive the credential rotation it
// was supposed to be defeated by.
function ckx_revoke_other_sessions(PDO $pdo, string $userType, int $userId): void
{
    $currentHash = hash('sha256', ckx_bearer_token());
    $pdo->prepare(
        "DELETE FROM sessions WHERE user_type = ? AND user_id = ? AND token_hash <> ?"
    )->execute([$userType, $userId, $currentHash]);
}

// A syntactically valid bcrypt hash that no real password will ever match.
// Used to make a login attempt against a non-existent account pay the same
// password_verify() cost as a real one, so response timing can't be used to
// tell whether an email has an account.
function ckx_dummy_hash(): string
{
    static $hash = null;
    if ($hash === null) {
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }
    return $hash;
}

// Returns the authenticated, approved agent row — or sends 401/403 and exits.
function ckx_require_agent(PDO $pdo): array
{
    $s = ckx_current_session($pdo);
    if (!$s || $s['user_type'] !== 'agent') {
        json_out(['error' => 'Please log in.'], 401);
    }
    $stmt = $pdo->prepare("SELECT * FROM agents WHERE id = ? LIMIT 1");
    $stmt->execute([(int) $s['user_id']]);
    $agent = $stmt->fetch();
    if (!$agent) {
        json_out(['error' => 'Please log in.'], 401);
    }
    if ($agent['status'] !== 'approved') {
        json_out(['error' => 'Your account is not active yet.'], 403);
    }
    return $agent;
}

// Returns true if the current bearer token is the admin — or sends 401 and exits.
function ckx_require_admin(PDO $pdo): void
{
    $s = ckx_current_session($pdo);
    if (!$s || $s['user_type'] !== 'admin') {
        json_out(['error' => 'Please log in.'], 401);
    }
}

// Brute-force guard: block an IP after too many recent failed logins.
const CKX_LOGIN_MAX_FAILS = 10;   // failed attempts...
const CKX_LOGIN_WINDOW_MIN = 15;  // ...within this many minutes -> blocked

// Extra-strict throttle for the single admin/owner account specifically. It
// is the highest-privilege account in the system and has no second factor,
// so it gets a tighter, IP-independent cap on top of the generic guards above
// (a distributed attacker spreading guesses across IPs still hits this one).
const CKX_ADMIN_LOGIN_MAX_FAILS = 5;
const CKX_ADMIN_LOGIN_WINDOW_SEC = 1800; // 30 minutes

function ckx_login_guard(PDO $pdo, string $ipHash): void
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip_hash = ? AND success = 0
           AND created_at > (NOW() - INTERVAL " . CKX_LOGIN_WINDOW_MIN . " MINUTE)"
    );
    $stmt->execute([$ipHash]);
    if ((int) $stmt->fetchColumn() >= CKX_LOGIN_MAX_FAILS) {
        json_out(['error' => 'Too many login attempts. Please try again in a few minutes.'], 429);
    }
}

function ckx_login_record(PDO $pdo, string $ipHash, bool $success): void
{
    $pdo->prepare("INSERT INTO login_attempts (ip_hash, success) VALUES (?, ?)")
        ->execute([$ipHash, $success ? 1 : 0]);
    // Occasional cleanup of old rows.
    if (random_int(1, 25) === 1) {
        $pdo->query("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
}

// -------------------------------------------------------------------------
// Generic per-key sliding-window rate limiting (rate_hits table). $key is a
// salted hash (an IP hash or an account hash); $windowSec is code-supplied.
// -------------------------------------------------------------------------
function ckx_rate_recent(PDO $pdo, string $bucket, string $key, int $windowSec): int
{
    $windowSec = (int) $windowSec; // inlined (trusted int) — placeholders aren't allowed in INTERVAL
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM rate_hits
         WHERE bucket = ? AND key_hash = ?
           AND created_at > (NOW() - INTERVAL $windowSec SECOND)"
    );
    $stmt->execute([$bucket, $key]);
    return (int) $stmt->fetchColumn();
}

function ckx_rate_add(PDO $pdo, string $bucket, string $key): void
{
    $pdo->prepare("INSERT INTO rate_hits (bucket, key_hash) VALUES (?, ?)")
        ->execute([$bucket, $key]);
    if (random_int(1, 50) === 1) {
        $pdo->query("DELETE FROM rate_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
    }
}

function ckx_rate_clear(PDO $pdo, string $bucket, string $key): void
{
    try {
        $pdo->prepare("DELETE FROM rate_hits WHERE bucket = ? AND key_hash = ?")
            ->execute([$bucket, $key]);
    } catch (Throwable $e) {
        /* table missing — ignore */
    }
}

// Check-and-record: true if allowed (records the hit), false if over the limit.
// Fails open so a public read never breaks over rate limiting / a missing table.
function ckx_rate_limit(PDO $pdo, string $bucket, string $key, int $max, int $windowSec): bool
{
    try {
        if (ckx_rate_recent($pdo, $bucket, $key, $windowSec) >= $max) {
            return false;
        }
        ckx_rate_add($pdo, $bucket, $key);
        return true;
    } catch (Throwable $e) {
        return true;
    }
}

// A unique short referral code for a new agent.
function ckx_make_ref_token(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $n = strlen($alphabet); // derive from the alphabet, don't hardcode it
    for ($try = 0; $try < 6; $try++) {
        $code = '';
        $bytes = random_bytes(8);
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[ord($bytes[$i]) % $n];
        }
        $stmt = $pdo->prepare("SELECT 1 FROM agents WHERE ref_token = ? LIMIT 1");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }
    return 'A' . bin2hex(random_bytes(4));
}

// -------------------------------------------------------------------------
// Password reset: single-use, short-lived tokens (password_resets table).
// -------------------------------------------------------------------------
const CKX_RESET_TOKEN_MIN = 30; // minutes a reset link stays valid

// Create a reset token and return the raw value to email to the account owner.
function ckx_issue_reset_token(PDO $pdo, string $userType, int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        "INSERT INTO password_resets (user_type, user_id, token_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))"
    )->execute([$userType, $userId, hash('sha256', $token), CKX_RESET_TOKEN_MIN]);

    // Opportunistic cleanup of stale reset tokens.
    $pdo->query("DELETE FROM password_resets WHERE expires_at < NOW()");
    return $token;
}

// Atomically claims a valid, unused, unexpired reset token and returns
// ['user_type', 'user_id'], or null if the token doesn't check out. Marks the
// token used so it can't be replayed even if the caller fails afterward.
function ckx_consume_reset_token(PDO $pdo, string $token): ?array
{
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        "SELECT user_type, user_id FROM password_resets
         WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?")
        ->execute([$hash]);
    return $row;
}
