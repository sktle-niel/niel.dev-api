<?php
// Visitor activity — powers the GitHub-style contribution heatmap on the
// landing page. One count per unique visitor per day (deduped by a salted
// IP+day hash, so a raw IP is never stored and reloads never inflate it).
//   POST /visits.php  -> record today's visit (idempotent per visitor/day)
//   GET  /visits.php  -> { total, max, days: { "YYYY-MM-DD": count } }  (~1 year)

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $pdo = db();

    if ($method === 'POST') {
        require_allowed_origin($config['allowed_origins']); // only our own site may count
        $today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $hash  = hash('sha256', client_ip() . '|visit|' . $config['ip_salt'] . '|' . $today);
        $pdo->prepare("INSERT IGNORE INTO visits (day, visitor_hash) VALUES (?, ?)")
            ->execute([$today, $hash]);
        json_out(['ok' => true]);
    }

    // GET — daily unique-visitor counts for the trailing ~53 weeks (one year).
    $rows = $pdo->query(
        "SELECT day, COUNT(*) AS c
           FROM visits
          WHERE day >= (CURDATE() - INTERVAL 371 DAY)
          GROUP BY day"
    )->fetchAll();

    $days  = [];
    $total = 0;
    $max   = 0;
    foreach ($rows as $r) {
        $c = (int) $r['c'];
        $days[$r['day']] = $c;
        $total += $c;
        if ($c > $max) {
            $max = $c;
        }
    }

    // Days a project was completed (progress hit 100%) — drawn as a green mark.
    // Isolated so a pre-migration DB (no completed_at column) still returns visits.
    $completions = [];
    try {
        $cr = $pdo->query(
            "SELECT DATE(completed_at) d, COUNT(*) c
               FROM project_requests
              WHERE completed_at IS NOT NULL
                AND completed_at >= (CURDATE() - INTERVAL 371 DAY)
              GROUP BY DATE(completed_at)"
        )->fetchAll();
        foreach ($cr as $row) {
            $completions[$row['d']] = (int) $row['c'];
        }
    } catch (Throwable $e) {
        // completed_at column not present yet — skip completions.
    }

    json_out([
        'total'       => $total,
        'max'         => $max,
        'days'        => (object) $days,
        'completions' => (object) $completions,
    ]);
} catch (Throwable $e) {
    error_log('CKX visits error: ' . $e->getMessage());
    // Never break the landing page over analytics — hand back an empty set.
    json_out(['total' => 0, 'max' => 0, 'days' => (object) [], 'completions' => (object) []], 200);
}
