<?php
// Scheduled housekeeping — run this from a cron job (e.g. hPanel > Cron Jobs,
// once a day), not from the web. It backstops the probabilistic inline
// cleanup in app/auth.php: those only fire on a random fraction of writes, so
// under sustained abuse traffic the throttle tables can otherwise grow faster
// than they're pruned.
//
//   php bin/cleanup.php
//
// Safe to run as often as you like — every statement is a bounded DELETE.

require __DIR__ . '/../app/env.php';
load_env(dirname(__DIR__) . '/.env');
require __DIR__ . '/../app/db.php';

$pdo = db();

$deleted = [];
$deleted['sessions'] = $pdo->exec("DELETE FROM sessions WHERE expires_at < NOW()");
$deleted['rate_hits'] = $pdo->exec("DELETE FROM rate_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
$deleted['login_attempts'] = $pdo->exec("DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");
$deleted['password_resets'] = $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW()");

foreach ($deleted as $table => $count) {
    echo "$table: removed $count row(s)\n";
}
