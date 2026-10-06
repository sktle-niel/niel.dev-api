<?php
// Loads the environment and shared helpers, then returns the config array.
// Every endpoint starts with:  $config = require __DIR__ . '/../app/bootstrap.php';

require __DIR__ . '/env.php';
load_env(dirname(__DIR__) . '/.env'); // .env lives at the project root, outside the web root

require __DIR__ . '/http.php';
require __DIR__ . '/input.php';
require __DIR__ . '/db.php';
require __DIR__ . '/mailer.php';
require __DIR__ . '/validation.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/settings.php';

$config = require __DIR__ . '/config.php';

// A wildcard origin silently disables the Origin-based CSRF check
// (require_allowed_origin() in http.php) on every state-changing endpoint.
// That's fine for local dev but easy to leave behind by accident, so flag it
// loudly unless this is explicitly a development environment.
if (($config['app_env'] ?? 'production') !== 'development' && in_array('*', $config['allowed_origins'], true)) {
    error_log(
        'CKX WARNING: ALLOWED_ORIGINS is "*" while APP_ENV is not "development". '
        . 'This disables the Origin-based CSRF check on all state-changing endpoints. '
        . 'Set ALLOWED_ORIGINS to your real site origin(s), or set APP_ENV=development locally.'
    );
}

return $config;
