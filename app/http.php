<?php
// Small HTTP helpers shared by every endpoint.

// Emit CORS headers for an allowed origin and short-circuit preflight requests.
function cors(array $allowed): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowAny = in_array('*', $allowed, true);

    if ($origin !== '' && ($allowAny || in_array($origin, $allowed, true))) {
        header('Access-Control-Allow-Origin: ' . ($allowAny ? '*' : $origin));
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
    }
    header('Vary: Origin');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Baseline security headers for every response.
function security_headers(): void
{
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');

    // HSTS — only meaningful over HTTPS (browsers ignore it on plain HTTP).
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Require a JSON request body. A cross-site HTML <form> cannot send
// "application/json" without a CORS preflight, so this blocks basic CSRF posts.
function require_json(): void
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') === false) {
        json_out(['error' => 'Unsupported content type. Send JSON.'], 415);
    }
}

// For state-changing requests, require a known browser Origin (CSRF hardening).
// Skipped when the allow-list is "*" (development).
function require_allowed_origin(array $allowed): void
{
    if (in_array('*', $allowed, true)) {
        return;
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || !in_array($origin, $allowed, true)) {
        json_out(['error' => 'Request blocked.'], 403);
    }
}

// Send a JSON response and stop.
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Decode the JSON request body into an array (empty array if missing/invalid).
// Rejects oversized bodies to avoid memory abuse.
function read_json_body(int $maxBytes = 65536): array
{
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        json_out(['error' => 'Request too large.'], 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw !== false && strlen($raw) > $maxBytes) {
        json_out(['error' => 'Request too large.'], 413);
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}
