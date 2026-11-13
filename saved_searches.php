<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
if (is_post()) {
    check_csrf();
    q('DELETE FROM saved_searches WHERE id = ? AND user_id = ?', [(int)($_POST['id'] ?? 0), $u['id']]);
    flash('Saved search removed.');
    redirect('saved_searches.php');
}
$rows = q('SELECT * FROM saved_searches WHERE user_id = ? ORDER BY id DESC', [$u['id']])->fetchAll();
page_start('Saved searches', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<p class="pad muted" style="margin:0">We check these every 15 minutes and tell you in your inbox<?= push_enabled() ? ', by push notification' : '' ?> and by email when new items match.</p>
<?php if (!$rows) empty_state('bookmark', 'No saved searches', 'Search or filter the catalog, then tap "Save search" to get alerts for new matches.', 'Browse items', 'catalog.php'); ?>
<?php foreach ($rows as $r): $f = catalog_filters((array)json_decode($r['filters'], true)); ?>
  <div class="list-row">
    <span class="muted"><?= icon('search', 22) ?></span>
    <div class="grow"><a href="catalog.php?<?= e(catalog_query($f)) ?>&sort=newest"><?= e($r['label']) ?></a><div class="muted small">Saved <?= e(time_ago($r['created_at'])) ?></div></div>
    <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <button class="iconbtn" aria-label="Remove saved search <?= e($r['label']) ?>"><?= icon('trash', 20) ?></button></form>
  </div>
<?php endforeach; ?>
<?php page_end();
