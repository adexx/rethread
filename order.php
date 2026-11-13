<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$id = (int)($_GET['id'] ?? 0);
$o = order_get($id);
if (!$o || !in_array((int)$u['id'], [(int)$o['buyer_id'], (int)$o['seller_id']], true)) redirect('orders.php');
$isSeller = (int)$u['id'] === (int)$o['seller_id'];
if ($isSeller && $o['status'] === 'pending_payment') redirect('orders.php?side=sold');
$other = q('SELECT * FROM users WHERE id = ?', [$isSeller ? $o['buyer_id'] : $o['seller_id']])->fetch();
$point = $o['pickup_point_id'] ? q('SELECT * FROM pickup_points WHERE id = ?', [$o['pickup_point_id']])->fetch() : null;

if (is_post()) {
    check_csrf();
    $act = $_POST['action'] ?? '';
    $st = $o['status'];
    try {
        if ($isSeller && $act === 'label' && $st === 'paid') {
            if (shippo_enabled()) {
                $label = shippo_buy_label($o, $u, $point);
                q("UPDATE orders SET status = 'label_sent', tracking = ?, label_url = ?, tracking_url = ?, shippo_transaction = ?, label_cost = ?, updated_at = NOW() WHERE id = ?",
                  [$label['tracking'], $label['label_url'], $label['tracking_url'], $label['transaction_id'], $label['cost'], $id]);
            } else {
                q("UPDATE orders SET status = 'label_sent', tracking = ?, updated_at = NOW() WHERE id = ?", ['DEMO' . random_int(100000000, 999999999), $id]);
            }
            notify((int)$o['buyer_id'], 'The seller got the shipping label for your order.', 'order.php?id=' . $id, false);
            flash('Shipping label ready. Print it, pack the item and drop it off.');
            redirect('label.php?id=' . $id);
        } elseif ($isSeller && $act === 'ship' && $st === 'label_sent') {
            order_mark_shipped($id);
            flash('Marked as shipped.');
        } elseif (!$isSeller && $act === 'received' && ($st === 'delivered' || ($st === 'shipped' && $o['delivery_type'] === 'home'))) {
            order_release($id, 'buyer');
            flash('Thanks! The order is complete and the seller has been paid.');
        } elseif (!$isSeller && $act === 'problem' && in_array($st, ['shipped', 'at_pickup', 'delivered'], true)) {
            $why = trim((string)($_POST['why'] ?? ''));
            order_suspend($id, 'the buyer reported a problem' . ($why !== '' ? ' ("' . mb_substr($why, 0, 300) . '")' : '') . '.');
            foreach (q("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $a) notify((int)$a, 'New dispute on order #' . $id . '.', 'admin/order.php?id=' . $id);
            flash('Thanks for letting us know. The payment is on hold and our team will review it.');
        } elseif ($act === 'cancel' && in_array($st, ['paid', 'label_sent'], true)) {
            order_refund($id, true, $isSeller ? 'seller' : 'buyer');
            flash('Order canceled.' . ($isSeller ? '' : ' Your payment is being refunded.'));
        } elseif ($act === 'review') {
            review_submit($o, (int)$u['id'], (int)($_POST['rating'] ?? 0), (string)($_POST['body'] ?? ''));
            flash('Thanks for your review!');
        } elseif (!$isSeller && $act === 'abandon' && $st === 'pending_payment') {
            redirect('checkout_cancel.php?order=' . $id);
        }
    } catch (Throwable $e) {
        if (!($e instanceof RuntimeException)) monitor_capture($e, ['order' => (string)$id, 'action' => $act]);
        else error_log('Order ' . $id . ' action ' . $act . ': ' . $e->getMessage());
        flash($e instanceof StripeError || $e instanceof ShippoError || $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.', 'err');
    }
    redirect('order.php?id=' . $id);
}

$lines = q('SELECT oi.*, (SELECT COALESCE(p.thumb, p.path) FROM item_photos p WHERE p.item_id = oi.item_id ORDER BY p.sort LIMIT 1) AS photo FROM order_items oi WHERE oi.order_id = ?', [$id])->fetchAll();
$carrier = CARRIERS[$o['carrier']] ?? CARRIERS['usps'];
$isPickup = $o['delivery_type'] === 'pickup';
$steps = $isPickup
    ? ['paid' => 'Payment successful', 'label_sent' => 'Shipping label created', 'shipped' => 'Shipped and on its way', 'at_pickup' => 'Ready at the pick-up point', 'delivered' => 'Collected', 'completed' => 'Completed']
    : ['paid' => 'Payment successful', 'label_sent' => 'Shipping label created', 'shipped' => 'Shipped and on its way', 'delivered' => 'Delivered', 'completed' => 'Completed'];
$reached = array_search($o['status'], array_keys($steps), true);
if ($o['status'] === 'suspended') $reached = array_search($o['delivered_at'] ? 'delivered' : ($o['at_point_at'] ? 'at_pickup' : ($o['shipped_at'] ? 'shipped' : 'label_sent')), array_keys($steps), true);
$dropoffs = ($isSeller && in_array($o['status'], ['paid', 'label_sent'], true)) ? points_near($u, 'dropoff', 3) : [];
$orderReviews = $o['status'] === 'completed' ? q('SELECT r.*, u.username FROM reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.order_id = ? ORDER BY r.id', [$id])->fetchAll() : [];
$canReview = can_review($o, (int)$u['id']);
$autoAt = $o['delivered_at'] ? strtotime($o['delivered_at']) + PAYMENT_HOLD_HOURS * 3600 : null;

page_start('Order #' . $id, ['back' => 'orders.php?side=' . ($isSeller ? 'sold' : 'bought'), 'tab' => 'profile']);
?>
<?php foreach ($lines as $l): ?>
  <a class="co-item" href="item.php?id=<?= (int)$l['item_id'] ?>">
    <img src="<?= e(photo_url($l['photo'], (string)$l['item_id'])) ?>" alt="">
    <div class="co-info"><div class="co-title"><?= e($l['title']) ?></div><div class="co-price"><?= money($l['price']) ?></div></div>
  </a>
<?php endforeach; ?>

<div class="box">
  <div class="box-title"><?= icon($o['status'] === 'completed' ? 'check' : ($o['status'] === 'canceled' ? 'close' : 'clock'), 22) ?><?= e(ORDER_STATUS[$o['status']]) ?></div>
  <div class="box-line">
    <?php
    $hint = [
        'pending_payment' => isset($_GET['paid']) ? 'Thanks! We\'re confirming your payment with Stripe. This page updates in a few seconds.' : 'Your payment wasn\'t completed. The items are held for 30 minutes.',
        'paid' => $isSeller ? 'Get the shipping label and send the parcel within 5 days, or the order is canceled and refunded.' : 'Waiting for the seller to get the shipping label.',
        'label_sent' => $isSeller ? 'Print the label, drop the parcel off, then mark it as shipped. Tracking updates also do this automatically.' : 'The seller has the label and will ship soon.',
        'shipped' => $isSeller ? 'On its way. You\'ll be paid when the buyer confirms everything is OK.' : 'Once it arrives, check the item and confirm everything is OK.',
        'at_pickup' => $isSeller ? 'The parcel is at the pick-up point. You\'ll be paid after the buyer collects it and confirms.' : 'Your parcel is waiting for you. Show the code below and a photo ID at the counter.',
        'delivered' => $isSeller ? 'Delivered. You\'ll be paid when the buyer confirms, or automatically on ' . date('M j, g:ia', (int)$autoAt) . '.' : 'Check your item, then tap "Everything is OK". If there\'s a problem, report it before ' . date('M j, g:ia', (int)$autoAt) . '; after that the seller is paid automatically.',
        'completed' => $isSeller ? ($o['payment_mode'] === 'stripe' ? 'Your money is on its way to your bank via Stripe.' : 'The money is in your wallet.') : 'Enjoy your item!',
        'canceled' => $o['return_due'] ? ($isSeller ? 'The parcel wasn\'t collected and is being returned to you' . ($o['returned_at'] ? ' (sent back ' . date('M j', strtotime($o['returned_at'])) . ').' : '.') : 'You didn\'t collect this parcel in time, so it went back to the seller and you were refunded.') : ($o['paid_at'] ? ($isSeller ? 'This order was canceled.' : 'Your payment has been refunded. It can take 5–10 business days to appear.') : 'This checkout was not completed. Nothing was charged.'),
        'suspended' => 'A problem was reported. Payment is on hold while our team reviews it.',
    ][$o['status']];
    echo e($hint);
    ?>
  </div>
  <?php if ($o['tracking'] && in_array($o['status'], ['label_sent', 'shipped', 'at_pickup', 'delivered', 'suspended', 'completed'], true)): ?>
    <div class="box-line"><?= icon('truck', 22) ?>Tracking: <?php if ($o['tracking_url']): ?><a class="link" href="<?= e($o['tracking_url']) ?>" target="_blank" rel="noopener"><?= e($o['tracking']) ?></a><?php else: ?><?= e($o['tracking']) ?><?php endif; ?></div>
  <?php endif; ?>
</div>
<?php if ($o['status'] === 'at_pickup' && $point): ?>
  <div class="box" style="border-color:var(--accent);text-align:center">
    <?php if (!$isSeller): ?>
      <div class="muted small">Your pick-up code</div>
      <div style="font-size:40px;font-weight:700;letter-spacing:8px;margin:4px 0 8px;font-variant-numeric:tabular-nums"><?= e($o['pickup_code']) ?></div>
      <div class="muted small">Name on the parcel: <?= e($o['ship_name']) ?> · bring a photo ID</div>
    <?php endif; ?>
    <div class="box-line" style="justify-content:center"><?= icon('store', 20) ?><?= e($point['name']) ?></div>
    <div class="box-line" style="justify-content:center"><?= icon('pin', 20) ?><a class="link" href="https://www.google.com/maps/search/?api=1&query=<?= urlencode(point_address($point)) ?>" target="_blank" rel="noopener"><?= e(point_address($point)) ?></a></div>
    <?php if ($point['hours']): ?><div class="box-line" style="justify-content:center"><?= icon('clock', 20) ?><?= e($point['hours']) ?></div><?php endif; ?>
    <div class="box-line accent" style="justify-content:center">Collect by <?= e(date('l, M j', strtotime($o['pickup_deadline']))) ?>. After that it goes back to the seller and you're refunded.</div>
  </div>
<?php endif; ?>
<?php if ($dropoffs): ?>
  <div class="box">
    <div class="box-title"><?= icon('store', 22) ?>Drop it off nearby</div>
    <div class="box-line">Hand the labelled parcel to any partner shop below, or to any <?= e($o['carrier'] === 'fedex' ? 'FedEx' : 'USPS') ?> location. The shop scans it and the buyer is told it's on its way.</div>
    <?php foreach ($dropoffs as $d): ?>
      <div class="box-line" style="color:var(--text)"><?= icon('pin', 20) ?><span><?= e($d['name']) ?><?= $d['miles'] !== null ? ' · ' . e(number_format($d['miles'], 1)) . ' mi' : '' ?><br><span class="muted small"><?= e(point_address($d)) ?><?= $d['hours'] ? ' · ' . e($d['hours']) : '' ?></span></span></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($o['status'] === 'pending_payment' && isset($_GET['paid'])): ?><script>setTimeout(function(){location.reload()},4000)</script><?php endif; ?>

<?php if (!in_array($o['status'], ['canceled', 'pending_payment'], true)): ?>
<ul class="timeline">
  <?php $n = 0; foreach ($steps as $k => $label): ?>
    <li class="<?= $reached !== false && $n <= $reached ? 'done' : '' ?>"><?= e($label) ?></li>
  <?php $n++; endforeach; ?>
</ul>
<?php endif; ?>

<div class="pad" style="display:grid;gap:10px;padding-top:0">
  <form method="post" style="display:grid;gap:10px">
    <?= csrf_field() ?>
    <?php if ($isSeller && $o['status'] === 'paid'): ?><button class="btn btn-primary" name="action" value="label"><?= icon('truck', 20) ?>Get shipping label</button><?php endif; ?>
    <?php if ($isSeller && $o['status'] === 'label_sent'): ?>
      <a class="btn btn-outline" href="label.php?id=<?= $id ?>">Print shipping label</a>
      <button class="btn btn-primary" name="action" value="ship">Mark as shipped</button>
    <?php endif; ?>
    <?php if (!$isSeller && ($o['status'] === 'delivered' || ($o['status'] === 'shipped' && !$isPickup))): ?>
      <button class="btn btn-primary" name="action" value="received" data-confirm="Confirm everything is OK? The seller will be paid and this can't be undone.">Everything is OK</button>
    <?php endif; ?>
    <?php if (in_array($o['status'], ['paid', 'label_sent'], true)): ?>
      <button class="btn btn-ghost" name="action" value="cancel" data-confirm="Cancel this order? The buyer gets a full refund.">Cancel order</button>
    <?php endif; ?>
    <?php if (!$isSeller && $o['status'] === 'pending_payment' && !isset($_GET['paid'])): ?>
      <button class="btn btn-ghost" name="action" value="abandon">Release the items</button>
    <?php endif; ?>
  </form>
  <?php if (!$isSeller && in_array($o['status'], ['shipped', 'at_pickup', 'delivered'], true)): ?>
    <details><summary class="btn btn-danger">I have a problem</summary>
      <form method="post" class="mt-16"><?= csrf_field() ?><input type="hidden" name="action" value="problem">
        <label class="field"><span>What's wrong? (not received, damaged, not as described…)</span><textarea class="input" name="why" maxlength="300" required></textarea></label>
        <button class="btn btn-danger" data-confirm="Report a problem and put the payment on hold?">Report problem</button>
      </form>
    </details>
  <?php endif; ?>
</div>

<?php if ($o['status'] === 'completed'): ?>
  <h2 class="section-title flush" id="review">Reviews</h2>
  <?php foreach ($orderReviews as $r): ?>
    <div class="review">
      <div class="review-head"><span class="grow"><?= (int)$r['reviewer_id'] === (int)$u['id'] ? 'You' : e($r['username']) ?> rated <?= (int)$r['reviewer_id'] === (int)$u['id'] ? e($other['username']) : 'you' ?></span><?= stars((float)$r['rating'], 1, false) ?></div>
      <?php if ($r['body'] !== ''): ?><p><?= e($r['body']) ?></p><?php endif; ?>
      <?php if ($r['hidden']): ?><p class="muted small">Hidden by our moderators.</p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($canReview): ?>
    <form method="post" class="pad">
      <?= csrf_field() ?><input type="hidden" name="action" value="review">
      <p style="margin:0 0 4px">How was <?= $isSeller ? 'this buyer' : 'your purchase from ' . e($other['username']) ?>?</p>
      <div class="rate" role="radiogroup" aria-label="Rating">
        <?php for ($n = 5; $n >= 1; $n--): ?><input type="radio" id="rate<?= $n ?>" name="rating" value="<?= $n ?>" required><label for="rate<?= $n ?>" title="<?= $n ?> star<?= $n > 1 ? 's' : '' ?>">★</label><?php endfor; ?>
      </div>
      <label class="field"><span>Comment (optional, shown on <?= e($other['username']) ?>'s profile)</span><textarea class="input" name="body" maxlength="1000" placeholder="<?= $isSeller ? 'e.g. Paid quickly, friendly messages' : 'e.g. Fast shipping, exactly as described' ?>"></textarea></label>
      <button class="btn btn-primary">Post review</button>
    </form>
  <?php elseif (!$orderReviews): ?>
    <p class="pad muted small" style="margin:0">Reviews can be left for <?= REVIEW_WINDOW_DAYS ?> days after an order completes.</p>
  <?php endif; ?>
<?php endif; ?>

<h2 class="section-title flush">Delivery details</h2>
<div class="box">
  <div class="box-title"><span class="carrier-logo <?= e($o['carrier']) ?>"><?= e($carrier['logo']) ?></span><?= e($carrier['name']) ?></div>
  <div style="font-size:18px;margin-top:10px"><?= money($o['shipping_cost']) ?></div>
  <?php if ($point): ?>
    <div class="box-line"><?= icon('store', 22) ?><?= e($point['name']) ?></div>
    <div class="box-line"><?= icon('pin', 22) ?><?= e(point_address($point)) ?></div>
    <?php if ($point['hours']): ?><div class="box-line"><?= icon('clock', 22) ?><?= e($point['hours']) ?></div><?php endif; ?>
  <?php else: ?>
    <div class="box-line"><?= icon('home', 22) ?><?= e($o['ship_name'] . ', ' . $o['ship_address'] . ', ' . trim($o['ship_zip'] . ' ' . $o['ship_city'] . ' ' . $o['ship_state'])) ?></div>
  <?php endif; ?>
  <div class="box-line"><?= icon('clock', 22) ?><?= e($carrier['days']) ?></div>
</div>

<h2 class="section-title"><?= $isSeller ? 'Buyer' : 'Seller' ?></h2>
<a class="seller-card" href="member.php?id=<?= (int)$other['id'] ?>"><?= avatar($other, 44) ?><span style="flex:1;font-size:18px"><?= e($other['username']) ?></span><span class="muted"><?= icon('chevron') ?></span></a>

<h2 class="section-title">Price summary</h2>
<div class="summary">
  <div><span>Order</span><span><?= money($o['subtotal']) ?></span></div>
  <?php if ($o['bundle_discount'] > 0): ?><div class="discount"><span>Bundle discount</span><span>−<?= money($o['bundle_discount']) ?></span></div><?php endif; ?>
  <?php if ($isSeller): ?>
    <div><span>You receive</span><span><?= money(seller_payout($o)) ?></span></div>
  <?php else: ?>
    <div><span>Buyer Protection fee</span><span><?= money($o['buyer_fee']) ?></span></div>
    <div><span>Shipping</span><span><?= money($o['shipping_cost']) ?></span></div>
    <div><span>Sales tax</span><span><?= money($o['tax']) ?></span></div>
    <div style="font-size:20px"><span style="color:var(--text)"><?= $o['paid_at'] ? 'Total paid' : 'Total' ?></span><span><?= money($o['total']) ?></span></div>
  <?php endif; ?>
  <div class="tiny"><span>Ordered <?= e(date('M j, Y g:ia', strtotime($o['created_at']))) ?></span><span></span></div>
</div>
<?php page_end();
