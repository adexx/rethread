<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$rows = q('SELECT u.id, u.username, u.avatar_color, u.rating_avg, u.rating_count,
                  (SELECT COUNT(*) FROM items i WHERE i.user_id = u.id AND i.status = \'active\') AS listings
           FROM follows f JOIN users u ON u.id = f.followed_id
           WHERE f.follower_id = ? AND u.deleted_at IS NULL ORDER BY f.created_at DESC LIMIT 500', [$u['id']])->fetchAll();
$feed = following_feed((int)$u['id'], 24);
$favs = user_favorites();
page_start('Following', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<?php if (!$rows): ?>
  <?php empty_state('user', "You're not following anyone", 'Tap Follow on a member\'s profile to see their new listings first.', 'Browse items', 'catalog.php'); ?>
<?php else: ?>
  <div class="chips" style="padding-top:12px">
    <?php foreach ($rows as $r): ?><a class="chip" href="member.php?id=<?= (int)$r['id'] ?>"><?= e($r['username']) ?> · <?= (int)$r['listings'] ?></a><?php endforeach; ?>
  </div>
  <h2 class="section-title">New from members you follow</h2>
  <?php if ($feed): ?>
    <div class="grid"><?php foreach ($feed as $it) echo item_card($it, $favs); ?></div>
  <?php else: ?>
    <p class="pad muted">No active listings right now.</p>
  <?php endif; ?>
<?php endif; ?>
<?php page_end();
