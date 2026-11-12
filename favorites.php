<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$items = q(item_select() . ' JOIN favorites fv ON fv.item_id = i.id AND fv.user_id = ? ORDER BY fv.created_at DESC', [$u['id']])->fetchAll();
$favs = user_favorites();
page_start('Favorite items', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<div style="height:12px"></div>
<?php if ($items): ?>
  <div class="grid"><?php foreach ($items as $it) echo item_card($it, $favs); ?></div>
<?php else: ?>
  <?php empty_state('heart', 'No favorites yet', 'Tap the heart on items you like to save them here.', 'Browse items', 'catalog.php'); ?>
<?php endif; ?>
<?php page_end();
