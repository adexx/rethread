<?php
require __DIR__ . '/_inc.php';
require_admin();

$live = "status NOT IN ('canceled','pending_payment')";
$stats = q("SELECT
    COUNT(*) AS orders,
    COALESCE(SUM(CASE WHEN $live THEN subtotal - bundle_discount END), 0) AS gmv,
    COALESCE(SUM(CASE WHEN $live THEN buyer_fee END), 0) AS fees,
    COALESCE(SUM(CASE WHEN status IN ('paid','label_sent','shipped','at_pickup','delivered') THEN 1 END), 0) AS open_orders,
    COALESCE(SUM(CASE WHEN status = 'suspended' THEN 1 END), 0) AS disputes,
    COALESCE(SUM(CASE WHEN status = 'paid' AND created_at < NOW() - INTERVAL 5 DAY THEN 1 END), 0) AS late
  FROM orders")->fetch();
$users = q("SELECT COUNT(*) AS n, SUM(created_at > NOW() - INTERVAL 7 DAY) AS new7, SUM(banned) AS banned FROM users WHERE role = 'user'")->fetch();
$items = q("SELECT SUM(status = 'active') AS active, SUM(created_at > NOW() - INTERVAL 7 DAY) AS new7, SUM(status = 'removed') AS removed FROM items")->fetch();
$wallets = (float)q('SELECT COALESCE(SUM(wallet_balance), 0) FROM users')->fetchColumn();

// Daily sales (item value of non-canceled orders), last 14 days.
$rows = q("SELECT DATE(created_at) d, SUM(subtotal - bundle_discount) gmv, COUNT(*) n FROM orders
           WHERE $live AND created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY DATE(created_at)")->fetchAll();
$byDay = [];
foreach ($rows as $r) $byDay[$r['d']] = $r;
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $days[] = ['d' => $d, 'gmv' => (float)($byDay[$d]['gmv'] ?? 0), 'n' => (int)($byDay[$d]['n'] ?? 0)];
}
$maxGmv = max(1, max(array_column($days, 'gmv')));
$niceMax = $maxGmv <= 10 ? 10 : (int)(ceil($maxGmv / 10) * 10);

$disputes = q("SELECT o.*, b.username buyer, s.username seller,
                 (SELECT title FROM order_items WHERE order_id = o.id ORDER BY id LIMIT 1) title
               FROM orders o JOIN users b ON b.id = o.buyer_id JOIN users s ON s.id = o.seller_id
               WHERE o.status = 'suspended' ORDER BY o.updated_at")->fetchAll();
$recent = q("SELECT o.*, b.username buyer, s.username seller,
               (SELECT title FROM order_items WHERE order_id = o.id ORDER BY id LIMIT 1) title
             FROM orders o JOIN users b ON b.id = o.buyer_id JOIN users s ON s.id = o.seller_id
             ORDER BY o.created_at DESC LIMIT 8")->fetchAll();
$newUsers = q("SELECT id, username, email, created_at, avatar_color FROM users WHERE role = 'user' ORDER BY created_at DESC LIMIT 6")->fetchAll();
$log = q('SELECT l.*, u.username FROM admin_log l JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT 6')->fetchAll();
$statusCounts = q('SELECT status, COUNT(*) n FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

admin_start('Dashboard', 'dashboard');
?>
<div class="tiles">
  <div class="tile"><div class="t-label">Sales (item value)</div><div class="t-value"><?= money($stats['gmv']) ?></div><div class="t-sub"><?= (int)$stats['orders'] ?> orders all time</div></div>
  <div class="tile"><div class="t-label">Fee revenue</div><div class="t-value"><?= money($stats['fees']) ?></div><div class="t-sub">Buyer Protection fees</div></div>
  <a class="tile" href="orders.php?status=open"><div class="t-label">Open orders</div><div class="t-value"><?= (int)$stats['open_orders'] ?></div><div class="t-sub"><?= (int)$stats['late'] ?> unshipped for 5+ days</div></a>
  <a class="tile<?= $stats['disputes'] ? ' alert' : '' ?>" href="orders.php?status=suspended"><div class="t-label">Disputes</div><div class="t-value"><?= (int)$stats['disputes'] ?></div><div class="t-sub">Payment on hold</div></a>
  <a class="tile" href="users.php"><div class="t-label">Members</div><div class="t-value"><?= (int)$users['n'] ?></div><div class="t-sub">+<?= (int)$users['new7'] ?> this week · <?= (int)$users['banned'] ?> banned</div></a>
  <a class="tile" href="items.php"><div class="t-label">Active listings</div><div class="t-value"><?= (int)$items['active'] ?></div><div class="t-sub">+<?= (int)$items['new7'] ?> this week · <?= (int)$items['removed'] ?> removed</div></a>
  <div class="tile"><div class="t-label">Wallet balances owed</div><div class="t-value"><?= money($wallets) ?></div><div class="t-sub">Sum of seller wallets</div></div>
</div>

<div class="cols-2-1">
  <section class="panel">
    <h2>Daily sales, last 14 days</h2>
    <p class="panel-sub">Item value of orders placed each day (canceled orders excluded). Hover a bar for details.</p>
    <div class="chart">
      <div class="gridline" style="top:0"></div><div class="gridline" style="top:50%"></div>
      <span class="chart-max"><?= money($niceMax) ?></span>
      <div class="bars" role="img" aria-label="Daily sales for the last 14 days">
        <?php foreach ($days as $d): $h = $d['gmv'] / $niceMax * 100; ?>
          <div class="bar" tabindex="0">
            <span style="height:<?= $d['gmv'] > 0 ? max(2, $h) : 0 ?>%"></span>
            <div class="tip"><strong><?= e(date('D, M j', strtotime($d['d']))) ?></strong><br><?= money($d['gmv']) ?> · <?= $d['n'] ?> order<?= $d['n'] === 1 ? '' : 's' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="bar-axis"><?php foreach ($days as $n => $d): ?><span><?= $n % 2 === 1 ? e(date('j', strtotime($d['d']))) : '' ?></span><?php endforeach; ?></div>
    </div>
    <details class="mt-16"><summary class="note" style="cursor:pointer">Show as table</summary>
      <table class="tbl mt-16"><thead><tr><th>Day</th><th class="num">Orders</th><th class="num">Sales</th></tr></thead><tbody>
        <?php foreach (array_reverse($days) as $d): ?><tr><td><?= e(date('D, M j', strtotime($d['d']))) ?></td><td class="num"><?= $d['n'] ?></td><td class="num"><?= money($d['gmv']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </details>
  </section>
  <section class="panel">
    <h2>Orders by status</h2>
    <table class="tbl"><tbody>
      <?php foreach (['pending_payment', 'paid', 'label_sent', 'shipped', 'at_pickup', 'delivered', 'suspended', 'completed', 'canceled'] as $s): ?>
        <tr><td><a href="orders.php?status=<?= $s ?>"><?= status_badge($s) ?></a></td><td class="num"><?= (int)($statusCounts[$s] ?? 0) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
</div>

<?php if ($disputes): ?>
<section class="panel" style="border-color:var(--danger)">
  <h2>Disputes needing a decision <a href="orders.php?status=suspended">All disputes</a></h2>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Order</th><th>Item</th><th>Buyer</th><th>Seller</th><th class="num">Total</th><th>Waiting</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($disputes as $o): ?>
      <tr><td>#<?= (int)$o['id'] ?></td><td class="ell"><?= e($o['title']) ?></td><td><?= e($o['buyer']) ?></td><td><?= e($o['seller']) ?></td>
        <td class="num"><?= money($o['total']) ?></td><td class="muted"><?= e(time_ago($o['updated_at'])) ?></td>
        <td class="num"><a class="btn btn-primary btn-xs" href="order.php?id=<?= (int)$o['id'] ?>">Review</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<div class="cols">
  <section class="panel">
    <h2>Latest orders <a href="orders.php">All orders</a></h2>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>#</th><th>Item</th><th>Status</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $o): ?>
        <tr><td><a class="strong" href="order.php?id=<?= (int)$o['id'] ?>"><?= (int)$o['id'] ?></a></td>
          <td><div class="ell"><?= e($o['title']) ?></div><div class="note"><?= e($o['buyer']) ?> ← <?= e($o['seller']) ?></div></td>
          <td><?= status_badge($o['status']) ?></td><td class="num"><?= money($o['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <section class="panel">
    <h2>New members <a href="users.php">All members</a></h2>
    <table class="tbl"><tbody>
      <?php foreach ($newUsers as $m): ?>
        <tr><td><a class="row" href="user.php?id=<?= (int)$m['id'] ?>"><?= avatar($m, 30) ?><span><span class="strong"><?= e($m['username']) ?></span><br><span class="note"><?= e($m['email']) ?></span></span></a></td>
          <td class="num muted"><?= e(time_ago($m['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
  <section class="panel">
    <h2>Admin activity <a href="log.php">Full log</a></h2>
    <?php if (!$log): ?><p class="note" style="margin:0">No admin actions yet.</p><?php endif; ?>
    <table class="tbl"><tbody>
      <?php foreach ($log as $l): ?>
        <tr><td><strong><?= e($l['username']) ?></strong> <?= e(str_replace('_', ' ', $l['action'])) ?><?= $l['target_id'] ? ' ' . e($l['target_type']) . ' #' . (int)$l['target_id'] : '' ?><div class="note ell"><?= e($l['details']) ?></div></td>
          <td class="num muted"><?= e(time_ago($l['created_at'])) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
</div>
<?php admin_end();
