<?php
// Public project tracker. A client enters their reference to see how far along
// their project is. Status only — no prices or contact details are exposed.
//   GET /status.php?ref=CKX-XXXXXX
//     -> { found, reference, title, progress, note, images: [url...], created_at }

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

try {
    $ref = strtoupper(trim((string) ($_GET['ref'] ?? '')));
    // References look like CKX-7G2K9Q. Bail early on anything malformed.
    if (!preg_match('/^CKX-[A-Z0-9]{6}$/', $ref)) {
        json_out(['found' => false]);
    }

    $pdo = db();

    // Per-IP throttle so references can't be enumerated by hammering this endpoint.
    $ipHash = hash('sha256', client_ip() . '|status|' . $config['ip_salt']);
    if (!ckx_rate_limit($pdo, 'status', $ipHash, 60, 60)) { // 60 lookups / minute / IP
        json_out(['found' => false, 'error' => 'Too many requests. Please slow down.'], 429);
    }

    $stmt = $pdo->prepare(
        "SELECT id, reference, business_name, project_title, progress, progress_note, created_at
           FROM project_requests WHERE reference = ? LIMIT 1"
    );
    $stmt->execute([$ref]);
    $r = $stmt->fetch();
    if (!$r) {
        json_out(['found' => false]);
    }

    $img = $pdo->prepare("SELECT path FROM project_images WHERE request_id = ? ORDER BY id");
    $img->execute([(int) $r['id']]);
    $images = $img->fetchAll(PDO::FETCH_COLUMN);

    json_out([
        'found'      => true,
        'reference'  => $r['reference'],
        'title'      => $r['business_name'] ?: ($r['project_title'] ?: 'Your project'),
        'progress'   => (int) $r['progress'],
        'note'       => $r['progress_note'],
        'images'     => $images, // relative /uploads/... paths
        'created_at' => $r['created_at'],
    ]);
} catch (Throwable $e) {
    error_log('CKX status error: ' . $e->getMessage());
    json_out(['found' => false]);
}
