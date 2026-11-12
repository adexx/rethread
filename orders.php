<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$side = ($_GET['side'] ?? 'bought') === 'sold' ? 'sold' : 'bought';
$filter = in_array($_GET['f'] ?? '', ['all', 'progress', 'canceled', 'completed'], true) ? $_GET['f'] : 'progress';

$col = $side === 'sold' ? 'o.seller_id' : 'o.buyer_id';
$statusSql = [
    'all' => '',
    'progress' => " AND o.status IN ('pending_payment','paid','label_sent','shipped','at_pickup','delivered','suspended')",
    'canceled' => " AND o.status = 'canceled'",
    'completed' => " AND o.status = 'completed'",
][$filter];
$orders = q("SELECT o.*,
               (SELECT oi.title FROM order_items oi WHERE oi.order_id = o.id ORDER BY oi.id LIMIT 1) AS title,
               (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS n,
               (SELECT p.path FROM order_items oi JOIN item_photos p ON p.item_id = oi.item_id WHERE oi.order_id = o.id ORDER BY oi.id, p.sort LIMIT 1) AS photo
             FROM orders o WHERE $col = ? $statusSql" . ($side === 'sold' ? " AND o.status <> 'pending_payment'" : '') . " AND NOT (o.status = 'canceled' AND o.paid_at IS NULL) ORDER BY o.updated_at DESC, o.id DESC", [$u['id']])->fetchAll();

page_start('My orders', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<nav class="tabs">
  <a href="orders.php?side=sold&f=<?= $filter ?>" class="<?= $side === 'sold' ? 'on' : '' ?>">Sold</a>
  <a href="orders.php?side=bought&f=<?= $filter ?>" class="<?= $side === 'bought' ? 'on' : '' ?>">Bought</a>
</nav>
<div class="chips">
  <?php foreach (['all' => 'All', 'progress' => 'In progress', 'canceled' => 'Canceled', 'completed' => 'Completed'] as $k => $l): ?>
    <a class="chip<?= $filter === $k ? ' on' : '' ?>" href="orders.php?side=<?= $side ?>&f=<?= $k ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div>
<?php if (!$orders): ?>
  <?php empty_state('orders', 'No orders here', $side === 'sold' ? 'Items you sell will show up here.' : 'Items you buy will show up here.', $side === 'sold' ? 'Sell an item' : 'Start shopping', $side === 'sold' ? 'sell.php' : 'index.php'); ?>
<?php endif; ?>
<?php foreach ($orders as $o): $icon = $o['status'] === 'completed' ? 'check' : ($o['status'] === 'canceled' ? 'close' : 'clock'); ?>
  <a class="order-row" href="order.php?id=<?= (int)$o['id'] ?>">
    <span class="thumb"><img src="<?= e(photo_url($o['photo'], 'o' . $o['id'])) ?>" alt=""><span class="st"><?= icon($icon, 16) ?></span></span>
    <span class="o-main">
      <div class="o-title"><?= e($o['title']) ?><?= $o['n'] > 1 ? ' +' . ($o['n'] - 1) . ' more' : '' ?></div>
      <div class="o-sub"><?= money($side === 'sold' ? $o['subtotal'] - $o['bundle_discount'] : $o['total']) ?></div>
      <div class="o-sub"><?= e(ORDER_STATUS[$o['status']]) ?></div>
    </span>
    <span class="muted"><?= icon('chevron') ?></span>
  </a>
<?php endforeach; ?>
<?php page_end();
