<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$rows = q('SELECT u.id, u.username, u.avatar_color, b.created_at FROM blocks b JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = ? ORDER BY b.created_at DESC', [$u['id']])->fetchAll();
page_start('Blocked members', ['back' => 'settings.php', 'tab' => 'profile']);
?>
<p class="pad muted" style="margin:0">Blocked members can't message you, make offers or buy your items, and you can't buy theirs.</p>
<?php if (!$rows) empty_state('user', 'Nobody blocked', 'You can block a member from the ⋯ menu on their profile or in a chat.'); ?>
<?php foreach ($rows as $r): ?>
  <div class="list-row">
    <?= avatar($r, 40) ?>
    <div class="grow"><a href="member.php?id=<?= (int)$r['id'] ?>"><?= e($r['username']) ?></a><div class="muted small">Blocked <?= e(time_ago($r['created_at'])) ?></div></div>
    <form method="post" action="block.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="next" value="blocked.php">
      <button class="btn btn-outline btn-sm" name="action" value="unblock">Unblock</button></form>
  </div>
<?php endforeach; ?>
<?php page_end();
