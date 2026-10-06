<?php
// Configuration, sourced from environment variables (see .env / .env.example).
// Defaults match a local Laragon / XAMPP install (user "root", empty password).

$origins = getenv('ALLOWED_ORIGINS')
    ?: 'http://localhost:5173,http://localhost:4173,http://127.0.0.1:5173,http://127.0.0.1:4173';

$mailEnabled = getenv('MAIL_ENABLED');

return [
    // Set APP_ENV=development in your local .env to silence the "wildcard
    // CORS in production" warning below. Defaults to production so a missing
    // APP_ENV never hides the warning on a real deployment.
    'app_env' => getenv('APP_ENV') ?: 'production',

    // --- Database ---
    'host'    => getenv('DB_HOST') ?: 'localhost',
    'port'    => getenv('DB_PORT') ?: '3306',
    'db'      => getenv('DB_NAME') ?: 'codekathax',
    'user'    => getenv('DB_USER') ?: 'root',
    'pass'    => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'charset' => 'utf8mb4',

    // Secret used to hash visitor IPs for rate limiting (set IP_SALT in .env).
    'ip_salt' => getenv('IP_SALT') ?: 'ckx-local-dev-salt-change-me',

    // Browser origins (your frontend site) allowed to call this API.
    // Comma-separated in ALLOWED_ORIGINS. Use "*" to allow any origin (dev only).
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', $origins)))),

    // --- Email (PHPMailer over SMTP) ---
    // The submission is always saved to MySQL first; the email is best-effort.
    // For Gmail use an App Password (smtp.gmail.com:465, ssl). For Hostinger use
    // a mailbox you created in hPanel (smtp.hostinger.com:465, ssl).
    'mail' => [
        'enabled'   => $mailEnabled !== false ? filter_var($mailEnabled, FILTER_VALIDATE_BOOLEAN) : true,
        'host'      => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port'      => (int) (getenv('SMTP_PORT') ?: 465),
        'secure'    => getenv('SMTP_SECURE') ?: 'ssl', // 'ssl' (465) or 'tls' (587)
        'user'      => getenv('SMTP_USER') ?: '',
        'pass'      => getenv('SMTP_PASS') ?: '',
        'from'      => getenv('MAIL_FROM') ?: (getenv('SMTP_USER') ?: 'no-reply@codekathax.online'),
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'CODEKATHAX Website',
        'to'        => getenv('MAIL_TO') ?: 'niel.ladica07@gmail.com',
    ],

    // Per-client agent commission (percent). The default applies to new deals;
    // the admin can raise it per client, capped at the max.
    'commission_default_pct' => (int) (getenv('COMMISSION_DEFAULT_PCT') ?: 15),
    'commission_max_pct'     => (int) (getenv('COMMISSION_MAX_PCT') ?: 30),

    // Public site URL (used to build agent, tracker, and password-reset links
    // in emails). Always set SITE_URL explicitly in production .env.
    'site_url' => rtrim(getenv('SITE_URL') ?: 'https://codekathax.online', '/'),

    // Single owner/admin login for the admin dashboard. These are the initial
    // defaults; once the admin edits their Account, the DB values take over.
    'admin' => [
        'name'          => getenv('ADMIN_NAME') ?: 'Owner',
        'email'         => getenv('ADMIN_EMAIL') ?: 'niel.ladica07@gmail.com',
        'password_hash' => getenv('ADMIN_PASSWORD_HASH') ?: '',
    ],
];
