<?php
// Served as /sitemap.xml (see .htaccess). Without ?part it is a sitemap index; each part lists up to
// 10,000 listings by id range, so it stays fast however big the catalog gets.
require __DIR__ . '/inc/app.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
const SITEMAP_CHUNK = 10000;
$x = function (string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
$part = (string)($_GET['part'] ?? '');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

if ($part === '') {
    $maxId = (int)q("SELECT COALESCE(MAX(id), 0) FROM items WHERE status = 'active'")->fetchColumn();
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '<sitemap><loc>' . $x(app_url('sitemap.xml?part=pages')) . "</loc></sitemap>\n";
    for ($n = 0; $n * SITEMAP_CHUNK <= $maxId; $n++) echo '<sitemap><loc>' . $x(app_url('sitemap.xml?part=items-' . $n)) . "</loc></sitemap>\n";
    echo '</sitemapindex>';
    exit;
}

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
if ($part === 'pages') {
    foreach (['', 'catalog.php', 'browse.php', 'help.php', 'terms.php', 'privacy.php', 'prohibited.php', 'partner_apply.php'] as $p) {
        echo '<url><loc>' . $x(app_url($p)) . "</loc></url>\n";
    }
    foreach (categories() as $c) echo '<url><loc>' . $x(app_url('catalog.php?cat=' . (int)$c['id'])) . "</loc></url>\n";
} elseif (preg_match('/^items-(\d+)$/', $part, $m)) {
    $from = (int)$m[1] * SITEMAP_CHUNK;
    $rows = q("SELECT i.id, i.sort_at FROM items i JOIN users u ON u.id = i.user_id
               WHERE i.id > ? AND i.id <= ? AND i.status = 'active' AND u.banned = 0 AND u.vacation_mode = 0 ORDER BY i.id",
              [$from, $from + SITEMAP_CHUNK])->fetchAll();
    foreach ($rows as $r) {
        echo '<url><loc>' . $x(app_url('item.php?id=' . (int)$r['id'])) . '</loc><lastmod>' . date('Y-m-d', strtotime($r['sort_at'])) . "</lastmod></url>\n";
    }
}
echo '</urlset>';
