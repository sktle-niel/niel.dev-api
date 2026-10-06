<?php
// Public portfolio list — powers the "Selected Work" section on the landing
// page. Only enabled items are returned; content is admin-authored (already
// validated on the way in via admin.php), so nothing here needs sanitizing.
//   GET /works.php -> { works: [...] }

$config = require __DIR__ . '/../app/bootstrap.php';
cors($config['allowed_origins']);
security_headers();

try {
    $pdo = db();
    $rows = $pdo->query(
        "SELECT id, name, url, category, description, image, featured
           FROM works WHERE enabled = 1
          ORDER BY featured DESC, id DESC"
    )->fetchAll();

    $works = array_map(static function (array $r): array {
        $host = preg_replace('/^www\./i', '', (string) (parse_url($r['url'], PHP_URL_HOST) ?: ''));
        return [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'domain'      => $host,
            'url'         => $r['url'],
            'category'    => $r['category'],
            'description' => $r['description'],
            'image'       => $r['image'],
            'featured'    => (bool) $r['featured'],
        ];
    }, $rows);

    json_out(['works' => $works]);
} catch (Throwable $e) {
    error_log('CKX works error: ' . $e->getMessage());
    json_out(['works' => []]);
}
