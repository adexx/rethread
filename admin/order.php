<?php
require __DIR__ . '/_inc.php';
require_admin();
$id = (int)($_GET['id'] ?? 0);
$o = q('SELECT * FROM orders WHERE id = ?', [$id])->fetch();
if (!$o) { flash('Order not found.', 'err'); redirect('orders.php'); }

if (is_post()) {
    check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $st = $o['status'];
    $link = 'order.php?id=' . $id;
    $payout = round((float)$o['subtotal'] - (float)$o['bundle_discount'], 2);
    $pdo = db();
    try {
    if ($act === 'release' && in_array($st, ['shipped', 'at_pickup', 'delivered', 'suspended'], true)) {
        order_release($id, 'admin', $note);
        admin_log('release_payment', 'order', $id, money($payout) . ' to seller. ' . $note);
        flash('Payment released to the seller.');
    } elseif ($act === 'refund' && in_array($st, ['paid', 'label_sent', 'shipped', 'at_pickup', 'delivered', 'suspended'], true)) {
        $relist = !empty($_POST['relist']);
        order_refund($id, $relist, 'admin', $note);
        admin_log('refund_order', 'order', $id, 'Refunded ' . money($o['total']) . ($relist ? ', relisted items. ' : '. ') . $note);
        flash('Order canceled and buyer refunded.');
    } elseif ($act === 'hold' && in_array($st, ['paid', 'label_sent', 'shipped', 'at_pickup', 'delivered'], true)) {
        order_suspend($id, $note !== '' ? $note : 'support is reviewing it.');
        admin_log('hold_order', 'order', $id, $note);
        flash('Order put on hold.');
    } elseif ($act === 'at_point' && in_array($st, ['label_sent', 'shipped'], true) && $o['delivery_type'] === 'pickup') {
        order_mark_at_point($id, (int)user()['id']);
        admin_log('mark_at_point', 'order', $id, $note);
        flash('Marked as arrived at the pick-up point; the buyer has their code.');
    } elseif ($act === 'collected' && $st === 'at_pickup') {
        order_mark_collected($id, (int)user()['id']);
        admin_log('mark_collected', 'order', $id, $note);
        flash('Marked as collected.');
    } elseif ($act === 'message' && $note !== '') {
        $to = in_list((string)($_POST['to'] ?? 'both'), ['buyer', 'seller', 'both'], 'both');
        if ($to !== 'seller') notify((int)$o['buyer_id'], 'Support (order #' . $id . '): ' . $note, $link);
        if ($to !== 'buyer') notify((int)$o['seller_id'], 'Support (order #' . $id . '): ' . $note, $link);
        admin_log('message_order', 'order', $id, 'To ' . $to . ': ' . $note);
        flash('Message sent.');
    } else {
        flash("That action isn't available for this order.", 'err');
    }
    } catch (Throwable $e) {
        error_log('Admin order ' . $id . ': ' . $e->getMessage());
        flash('Failed: ' . $e->getMessage(), 'err');
    }
    redirect('order.php?id=' . $id);
}

$buyer = q('SELECT * FROM users WHERE id = ?', [$o['buyer_id']])->fetch();
$seller = q('SELECT * FROM users WHERE id = ?', [$o['seller_id']])->fetch();
$lines = q('SELECT oi.*, i.status item_status, (SELECT COALESCE(p.thumb, p.path) FROM item_photos p WHERE p.item_id = oi.item_id ORDER BY p.sort LIMIT 1) photo
            FROM order_items oi JOIN items i ON i.id = oi.item_id WHERE oi.order_id = ?', [$id])->fetchAll();
$point = $o['pickup_point_id'] ? q('SELECT * FROM pickup_points WHERE id = ?', [$o['pickup_point_id']])->fetch() : null;
$conv = q('SELECT c.id FROM conversations c JOIN order_items oi ON oi.item_id = c.item_id WHERE oi.order_id = ? AND c.buyer_id = ? LIMIT 1', [$id, $o['buyer_id']])->fetchColumn();
$msgs = $conv ? q('SELECT m.*, u.username FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.conversation_id = ? ORDER BY m.id', [$conv])->fetchAll() : [];
$history = q("SELECT l.*, u.username FROM admin_log l JOIN users u ON u.id = l.admin_id WHERE l.target_type = 'order' AND l.target_id = ? ORDER BY l.id DESC", [$id])->fetchAll();
$carrier = CARRIERS[$o['carrier']] ?? CARRIERS['usps'];
$st = $o['status'];

admin_start('Order #' . $id, 'orders');
?>
<p style="margin:-8px 0 20px"><a class="link small" href="orders.php">← All orders</a> &nbsp; <?= status_badge($st) ?> <span class="note">Placed <?= e(date('M j, Y g:ia', strtotime($o['created_at']))) ?> · updated <?= e(time_ago($o['updated_at'])) ?></span></p>

<div class="cols-2-1">
  <div>
    <?php if ($st === 'suspended'): ?>
    <section class="panel" style="border-color:var(--danger)">
      <h2>Resolve dispute</h2>
      <p class="panel-sub" style="margin-top:-6px">The buyer reported a problem. Payment of <?= money($o['subtotal'] - $o['bundle_discount']) ?> is held. Review the chat below, then decide.</p>
    <?php else: ?>
    <section class="panel">
      <h2>Actions</h2>
    <?php endif; ?>
      <?php if (in_array($st, ['completed', 'canceled', 'pending_payment'], true)): ?>
        <p class="note" style="margin:0 0 12px">This order is closed. You can still message the buyer or seller.</p>
      <?php else: ?>
      <form method="post" class="stack">
        <?= csrf_field() ?>
        <label class="field" style="margin:0"><span>Note (sent to the members and saved in the log)</span><textarea class="input" name="note" maxlength="400" placeholder="e.g. Tracking shows delivered; seller provided proof of condition."></textarea></label>
        <label class="row small"><input type="checkbox" name="relist" value="1" checked style="accent-color:var(--accent)"> On refund, put the items back on sale</label>
        <div class="inline-form">
          <?php if (in_array($st, ['shipped', 'at_pickup', 'delivered', 'suspended'], true)): ?>
            <button class="btn btn-primary btn-sm" name="action" value="release" data-confirm="Complete the order and pay <?= e(money($o['subtotal'] - $o['bundle_discount'])) ?> to <?= e($seller['username']) ?>?">Release payment to seller</button>
          <?php endif; ?>
          <button class="btn btn-danger btn-sm" name="action" value="refund" data-confirm="Cancel the order and refund <?= e(money($o['total'])) ?> to <?= e($buyer['username']) ?>?">Cancel &amp; refund buyer</button>
          <?php if ($st !== 'suspended'): ?><button class="btn btn-sm" name="action" value="hold">Put on hold</button><?php endif; ?>
          <?php if ($o['delivery_type'] === 'pickup' && in_array($st, ['label_sent', 'shipped'], true)): ?><button class="btn btn-sm" name="action" value="at_point">Mark arrived at point</button><?php endif; ?>
          <?php if ($st === 'at_pickup'): ?><button class="btn btn-sm" name="action" value="collected" data-confirm="Mark as collected by the buyer? Their 48-hour check window starts now.">Mark collected</button><?php endif; ?>
        </div>
      </form>
      <?php endif; ?>
      <form method="post" class="inline-form mt-16">
        <?= csrf_field() ?><input type="hidden" name="action" value="message">
        <select class="input" name="to" style="flex:0 0 auto;width:auto" aria-label="Recipient"><option value="both">Both</option><option value="buyer">Buyer</option><option value="seller">Seller</option></select>
        <input class="input" name="note" maxlength="400" placeholder="Message from support" required aria-label="Message">
        <button class="btn btn-outline btn-sm">Send</button>
      </form>
    </section>

    <section class="panel">
      <h2>Items</h2>
      <table class="tbl"><tbody>
        <?php foreach ($lines as $l): ?>
          <tr><td class="thumbcell"><img src="../<?= e(photo_url($l['photo'], (string)$l['item_id'])) ?>" alt=""></td>
            <td><a class="strong" href="items.php?q=<?= (int)$l['item_id'] ?>"><?= e($l['title']) ?></a><div class="note">Item #<?= (int)$l['item_id'] ?> · listing <?= e($l['item_status']) ?></div></td>
            <td class="num"><?= money($l['price']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
    </section>

    <section class="panel">
      <h2>Buyer ↔ seller chat</h2>
      <?php if (!$msgs): ?><p class="note" style="margin:0">No messages about this item.</p><?php endif; ?>
      <div class="chat" style="padding:0">
        <?php foreach ($msgs as $m): $fromBuyer = (int)$m['sender_id'] === (int)$o['buyer_id']; ?>
          <div class="bubble<?= $fromBuyer ? '' : ' me' ?>"><strong class="small"><?= e($m['username']) ?>:</strong> <?= e($m['body']) ?><span class="b-time"><?= e(date('M j, g:ia', strtotime($m['created_at']))) ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <div>
    <section class="panel">
      <h2>Money</h2>
      <dl class="kv">
        <dt>Items</dt><dd><?= money($o['subtotal']) ?></dd>
        <?php if ($o['bundle_discount'] > 0): ?><dt>Bundle discount</dt><dd>−<?= money($o['bundle_discount']) ?></dd><?php endif; ?>
        <dt>Buyer Protection fee</dt><dd><?= money($o['buyer_fee']) ?></dd>
        <dt>Shipping</dt><dd><?= money($o['shipping_cost']) ?></dd>
        <dt>Sales tax</dt><dd><?= money($o['tax']) ?></dd>
        <dt><strong>Buyer paid</strong></dt><dd><strong><?= money($o['total']) ?></strong></dd>
        <dt>Seller gets</dt><dd><?= money($o['subtotal'] - $o['bundle_discount']) ?></dd>
        <dt>Payment</dt><dd><?= $o['payment_mode'] === 'stripe' ? 'Stripe' . ($o['stripe_payment_intent'] ? ' · <a class="link" target="_blank" rel="noopener" href="https://dashboard.stripe.com/' . (stripe_is_test() ? 'test/' : '') . 'payments/' . e($o['stripe_payment_intent']) . '">view</a>' : '') : 'Demo' ?></dd>
        <?php if ($o['stripe_transfer']): ?><dt>Transfer</dt><dd><span class="note"><?= e($o['stripe_transfer']) ?></span></dd><?php endif; ?>
        <?php if ($o['stripe_refund']): ?><dt>Refund</dt><dd><span class="note"><?= e($o['stripe_refund']) ?></span></dd><?php endif; ?>
      </dl>
    </section>
    <section class="panel">
      <h2>People</h2>
      <dl class="kv">
        <dt>Buyer</dt><dd><a class="link" href="user.php?id=<?= (int)$buyer['id'] ?>"><?= e($buyer['username']) ?></a><br><span class="note"><?= e($buyer['email']) ?></span></dd>
        <dt>Seller</dt><dd><a class="link" href="user.php?id=<?= (int)$seller['id'] ?>"><?= e($seller['username']) ?></a><br><span class="note"><?= e($seller['email']) ?></span></dd>
        <dt>Contact</dt><dd><?= e($o['contact_phone']) ?></dd>
      </dl>
    </section>
    <section class="panel">
      <h2>Delivery</h2>
      <dl class="kv">
        <dt>Carrier</dt><dd><?= e($carrier['name']) ?> · parcel <?= e($o['parcel_size']) ?></dd>
        <dt>Tracking</dt><dd><?php if ($o['tracking_url']): ?><a class="link" href="<?= e($o['tracking_url']) ?>" target="_blank" rel="noopener"><?= e($o['tracking']) ?></a><?php else: ?><?= e($o['tracking'] ?: '—') ?><?php endif; ?><?= $o['label_url'] ? ' · <a class="link" href="' . e($o['label_url']) . '" target="_blank" rel="noopener">label</a>' : '' ?></dd>
        <?php if ($o['label_cost'] !== null): ?><dt>Label cost</dt><dd><?= money($o['label_cost']) ?> <span class="note">(buyer paid <?= money($o['shipping_cost']) ?>)</span></dd><?php endif; ?>
        <?php if ($point): ?><dt>Pick-up</dt><dd><a class="link" href="pickup.php?edit=<?= (int)$point['id'] ?>"><?= e($point['name']) ?></a><br><span class="note"><?= e(point_address($point)) ?></span></dd>
        <?php if ($o['pickup_code']): ?><dt>Pick-up code</dt><dd><?= e($o['pickup_code']) ?><span class="note"> · arrived <?= e($o['at_point_at'] ? date('M j', strtotime($o['at_point_at'])) : '—') ?> · collect by <?= e($o['pickup_deadline'] ? date('M j', strtotime($o['pickup_deadline'])) : '—') ?><?= $o['collected_at'] ? ' · collected ' . e(date('M j', strtotime($o['collected_at']))) : '' ?></span></dd><?php endif; ?>
        <?php if ($o['return_due']): ?><dt>Return</dt><dd><?= e($o['return_tracking']) ?> <span class="note"><?= $o['returned_at'] ? 'handed to carrier ' . e(date('M j', strtotime($o['returned_at']))) : 'waiting at the shop' ?></span></dd><?php endif; ?>
        <?php else: ?><dt>Ship to</dt><dd><?= e($o['ship_name']) ?><br><span class="note"><?= e($o['ship_address'] . ', ' . trim($o['ship_zip'] . ' ' . $o['ship_city'] . ' ' . $o['ship_state'])) ?></span></dd><?php endif; ?>
      </dl>
    </section>
    <section class="panel">
      <h2>Admin history</h2>
      <?php if (!$history): ?><p class="note" style="margin:0">No admin actions on this order.</p><?php endif; ?>
      <?php foreach ($history as $h): ?>
        <p class="small" style="margin:0 0 8px"><strong><?= e($h['username']) ?></strong> <?= e(str_replace('_', ' ', $h['action'])) ?> <span class="note">· <?= e(time_ago($h['created_at'])) ?></span><br><span class="note"><?= e($h['details']) ?></span></p>
      <?php endforeach; ?>
    </section>
  </div>
</div>
<?php admin_end();
