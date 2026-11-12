<?php
require __DIR__ . '/_inc.php';
require_admin();

$status = in_list((string)($_GET['status'] ?? 'all'), ['all', 'open', 'pending_payment', 'paid', 'label_sent', 'shipped', 'at_pickup', 'delivered', 'suspended', 'completed', 'canceled'], 'all');
$qText = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($status === 'open') $where[] = "o.status IN ('paid','label_sent','shipped','at_pickup','delivered')";
elseif ($status !== 'all') { $where[] = 'o.status = ?'; $params[] = $status; }
if ($qText !== '') {
    $where[] = '(o.id = ? OR b.username LIKE ? OR s.username LIKE ? OR o.tracking LIKE ? OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.title LIKE ?))';
    $like = '%' . $qText . '%';
    array_push($params, ctype_digit($qText) ? (int)$qText : 0, $like, $like, $like, $like);
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$from = ' FROM orders o JOIN users b ON b.id = o.buyer_id JOIN users s ON s.id = o.seller_id';
$total = (int)q('SELECT COUNT(*)' . $from . $w, $params)->fetchColumn();
[$offset, $pager] = paginate($total, 25);
$orders = q('SELECT o.*, b.username buyer, s.username seller,
               (SELECT title FROM order_items WHERE order_id = o.id ORDER BY id LIMIT 1) title,
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) n' . $from . $w .
            " ORDER BY o.status = 'suspended' DESC, o.created_at DESC LIMIT 25 OFFSET $offset", $params)->fetchAll();
$counts = q('SELECT status, COUNT(*) FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$counts['all'] = array_sum($counts);
$counts['open'] = ($counts['paid'] ?? 0) + ($counts['label_sent'] ?? 0) + ($counts['shipped'] ?? 0) + ($counts['at_pickup'] ?? 0) + ($counts['delivered'] ?? 0);

admin_start('Orders', 'orders');
?>
<form class="toolbar">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input class="input" name="q" value="<?= e($qText) ?>" placeholder="Order #, member, item or tracking" aria-label="Search orders">
  <button class="btn btn-sm btn-outline">Search</button>
  <?php if ($qText !== ''): ?><a class="link small" href="orders.php?status=<?= e($status) ?>">Clear</a><?php endif; ?>
</form>
<div class="toolbar">
  <div class="seg">
    <?php foreach (['all' => 'All', 'open' => 'Open', 'suspended' => 'Disputed', 'paid' => 'Paid', 'label_sent' => 'Label sent', 'shipped' => 'Shipped', 'at_pickup' => 'At pick-up', 'delivered' => 'Delivered', 'completed' => 'Completed', 'canceled' => 'Canceled', 'pending_payment' => 'Awaiting payment'] as $k => $l): ?>
      <a class="<?= $status === $k ? 'on' : '' ?>" href="orders.php?status=<?= $k ?>&q=<?= urlencode($qText) ?>"><?= $l ?><em><?= (int)($counts[$k] ?? 0) ?></em></a>
    <?php endforeach; ?>
  </div>
</div>
<section class="panel">
  <?php if (!$orders): ?><p class="note" style="margin:0">No orders match.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>#</th><th>Items</th><th>Buyer</th><th>Seller</th><th>Delivery</th><th>Status</th><th class="num">Item value</th><th class="num">Fee</th><th class="num">Total</th><th>Placed</th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td><a class="strong" href="order.php?id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td>
        <td><a class="ell" style="display:block" href="order.php?id=<?= (int)$o['id'] ?>"><?= e($o['title']) ?><?= $o['n'] > 1 ? ' +' . ($o['n'] - 1) : '' ?></a></td>
        <td><a href="user.php?id=<?= (int)$o['buyer_id'] ?>"><?= e($o['buyer']) ?></a></td>
        <td><a href="user.php?id=<?= (int)$o['seller_id'] ?>"><?= e($o['seller']) ?></a></td>
        <td class="muted"><?= $o['delivery_type'] === 'pickup' ? 'Pick-up' : e(strtoupper($o['carrier'])) ?></td>
        <td><?= status_badge($o['status']) ?></td>
        <td class="num"><?= money($o['subtotal'] - $o['bundle_discount']) ?></td>
        <td class="num"><?= money($o['buyer_fee']) ?></td>
        <td class="num"><?= money($o['total']) ?></td>
        <td class="muted" style="white-space:nowrap"><?= e(date('M j, g:ia', strtotime($o['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= $pager ?>
  <?php endif; ?>
</section>
<?php admin_end();
