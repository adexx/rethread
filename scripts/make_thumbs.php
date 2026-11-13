<?php
// Creates thumbnails for photos uploaded before thumbnails existed. Safe to run more than once.
//   php scripts/make_thumbs.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../inc/app.php';
$done = $failed = 0;
$last = 0;
do {
    $rows = q("SELECT id, path FROM item_photos WHERE thumb IS NULL AND path LIKE 'uploads/%' AND id > ? ORDER BY id LIMIT 200", [$last])->fetchAll();
    foreach ($rows as $r) {
        $last = (int)$r['id'];
        $thumb = make_thumb_for($r['path']);
        if ($thumb) { q('UPDATE item_photos SET thumb = ? WHERE id = ?', [$thumb, $r['id']]); $done++; }
        else $failed++;
    }
} while ($rows);
echo "Thumbnails created: $done" . ($failed ? ", skipped (file missing or unreadable): $failed" : '') . "\n";
