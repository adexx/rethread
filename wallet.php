<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$pending = (float)q("SELECT COALESCE(SUM(subtotal - bundle_discount), 0) FROM orders WHERE seller_id = ? AND status IN ('paid','label_sent','shipped','at_pickup','delivered','suspended')", [$u['id']])->fetchColumn();
$history = q("SELECT o.id, o.subtotal - o.bundle_discount AS amount, o.updated_at,
                (SELECT title FROM order_items WHERE order_id = o.id ORDER BY id LIMIT 1) AS title
              FROM orders o WHERE o.seller_id = ? AND o.status = 'completed' ORDER BY o.updated_at DESC", [$u['id']])->fetchAll();
page_start('Wallet', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<div class="box mt-16">
  <div class="muted"><?= stripe_enabled() ? 'Total earned' : 'Available balance' ?></div>
  <div style="font-size:34px;font-weight:600;margin:4px 0"><?= money($u['wallet_balance']) ?></div>
  <div class="muted small">Pending: <?= money($pending) ?> from orders in progress</div>
  <?php if (stripe_enabled()): ?>
    <p class="hint">This is the total you've earned. Each completed sale is sent automatically to your bank through Stripe.</p>
    <a class="btn btn-primary mt-16" href="payouts.php"><?= $u['payouts_enabled'] ? 'View payouts on Stripe' : 'Set up payouts' ?></a>
  <?php else: ?>
    <button class="btn btn-primary mt-16" disabled title="Payouts aren't available in demo mode">Transfer to bank</button>
    <p class="hint">Payouts are off on this server (demo mode).</p>
  <?php endif; ?>
</div>
<h2 class="section-title">History</h2>
<?php if (!$history) empty_state('wallet', 'No transactions yet', 'When a buyer confirms an order, the money appears here.'); ?>
<?php foreach ($history as $h): ?>
  <a class="order-row" href="order.php?id=<?= (int)$h['id'] ?>">
    <span class="o-main"><div class="o-title"><?= e($h['title']) ?></div><div class="o-sub"><?= e(date('M j, Y', strtotime($h['updated_at']))) ?></div></span>
    <span class="accent">+<?= money($h['amount']) ?></span>
  </a>
<?php endforeach; ?>
<?php page_end();
