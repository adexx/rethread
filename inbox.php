<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$tab = ($_GET['tab'] ?? 'messages') === 'notifications' ? 'notifications' : 'messages';
$counts = unread_counts();

$convs = q("SELECT c.*, i.title,
              (SELECT p.path FROM item_photos p WHERE p.item_id = c.item_id ORDER BY p.sort LIMIT 1) AS photo,
              ou.id AS other_id, ou.username AS other_name, ou.avatar_color,
              (SELECT m.body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
              (SELECT m.offer_amount FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_offer,
              (SELECT MAX(m.created_at) FROM messages m WHERE m.conversation_id = c.id) AS last_at,
              (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_id <> ? AND m.read_at IS NULL) AS unread
            FROM conversations c
            JOIN items i ON i.id = c.item_id
            JOIN users ou ON ou.id = IF(c.buyer_id = ?, c.seller_id, c.buyer_id)
            WHERE c.buyer_id = ? OR c.seller_id = ?
            ORDER BY last_at DESC", [$u['id'], $u['id'], $u['id'], $u['id']])->fetchAll();

$notes = [];
if ($tab === 'notifications') {
    $notes = q('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']])->fetchAll();
    q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$u['id']]);
}

page_start('Inbox', ['tab' => 'inbox']);
?>
<nav class="tabs">
  <a href="inbox.php" class="<?= $tab === 'messages' ? 'on' : '' ?>">Messages <?= count($convs) ?: '' ?></a>
  <a href="inbox.php?tab=notifications" class="<?= $tab === 'notifications' ? 'on' : '' ?>">Notifications<?= $counts['notifications'] ? ' ' . $counts['notifications'] : '' ?></a>
</nav>
<?php if ($tab === 'messages'): ?>
  <?php if (!$convs) empty_state('mail', 'No messages yet', 'Message a seller from any listing to ask a question or make an offer.'); ?>
  <?php foreach ($convs as $c): ?>
    <a class="conv<?= $c['unread'] ? ' unread' : '' ?>" href="conversation.php?id=<?= (int)$c['id'] ?>">
      <span class="thumb"><img src="<?= e(photo_url($c['photo'], (string)$c['item_id'])) ?>" alt=""><?= avatar(['username' => $c['other_name'], 'avatar_color' => $c['avatar_color']], 30) ?></span>
      <span class="c-main">
        <div class="c-name"><?= e($c['other_name']) ?></div>
        <div class="c-last"><?= $c['last_offer'] !== null ? money($c['last_offer']) : e($c['last_body'] ?: $c['title']) ?></div>
      </span>
      <span class="c-time"><?= $c['last_at'] ? e(time_ago($c['last_at'])) : '' ?></span>
    </a>
  <?php endforeach; ?>
<?php else: ?>
  <?php if (!$notes) empty_state('mail', 'No notifications'); ?>
  <?php foreach ($notes as $n): ?>
    <a class="conv<?= $n['read_at'] ? '' : ' unread' ?>" href="<?= e($n['link'] ?: '#') ?>">
      <span class="c-main"><div style="white-space:normal"><?= e($n['body']) ?></div><div class="muted small"><?= e(time_ago($n['created_at'])) ?></div></span>
      <span class="muted"><?= icon('chevron') ?></span>
    </a>
  <?php endforeach; ?>
<?php endif; ?>
<?php page_end();
