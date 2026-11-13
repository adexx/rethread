<?php
require __DIR__ . '/inc/app.php';
$u = require_verified();

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['items'] ?? $_POST['items'] ?? ''))))));
if (!$ids) redirect('index.php');
$ph = implode(',', array_fill(0, count($ids), '?'));
$items = q(item_select() . " WHERE i.id IN ($ph)", $ids)->fetchAll();

$error = null;
if (count($items) !== count($ids)) $error = 'One of these items no longer exists.';
$sellerIds = array_unique(array_map(function ($i) { return (int)$i['user_id']; }, $items));
if (!$error && count($sellerIds) !== 1) $error = 'A bundle can only contain items from one seller.';
if (!$error && in_array((int)$u['id'], $sellerIds, true)) $error = "You can't buy your own items.";
if (!$error) foreach ($items as $it) if ($it['status'] !== 'active') $error = '"' . $it['title'] . '" is no longer available.';
$seller = $items ? q('SELECT * FROM users WHERE id = ?', [$items[0]['user_id']])->fetch() : null;
if (!$error && ($seller['vacation_mode'] || $seller['banned'] || $seller['deleted_at'])) $error = 'This seller is not selling right now.';
if (!$error && is_blocked_between((int)$u['id'], (int)$seller['id'])) $error = "You can't buy from this member.";
if (!$error && stripe_enabled() && (!$seller['payouts_enabled'] || !$seller['stripe_account_id'])) $error = "This seller hasn't finished setting up payouts yet, so their items can't be bought right now.";
if ($error) { flash($error, 'err'); redirect(count($ids) === 1 ? 'item.php?id=' . $ids[0] : 'member.php?id=' . ($seller['id'] ?? 0)); }

// ---- Pricing ----
$lines = [];
$subtotal = 0.0;
foreach ($items as $it) {
    $p = effective_price($it, (int)$u['id']);
    $lines[] = ['item' => $it, 'price' => $p];
    $subtotal += $p;
}
$rate = bundle_rate($seller, count($items));
$discount = round($subtotal * $rate, 2);
$net = round($subtotal - $discount, 2);
$fee = buyer_fee($net);
$tax = round($net * tax_rate(), 2);
$base = round($net + $fee + $tax, 2);
$parcel = largest_parcel($items);
$pickupPrice = shipping_cost('pickup', $parcel);
$homeFrom = min(shipping_cost('usps', $parcel), shipping_cost('fedex', $parcel));
// Nearby partner shops that accept parcels and still have room.
$nearby = points_near($u, 'pickup', 10);
$points = array_values(array_filter($nearby, function ($p) { return !$p['full']; }));
$fullCount = count($nearby) - count($points);
$pickupAvailable = (bool)$points;

$delivery = in_array($_POST['delivery'] ?? '', ['pickup', 'home'], true) ? $_POST['delivery'] : ($pickupAvailable ? 'pickup' : 'home');
if (!$pickupAvailable) $delivery = 'home';
$carrier = in_array($_POST['carrier'] ?? '', ['usps', 'fedex'], true) ? $_POST['carrier'] : 'usps';
$pointId = (int)($_POST['point'] ?? ($points[0]['id'] ?? 0));
$phone = trim((string)($_POST['phone'] ?? $u['phone']));
$hasAddress = $u['address_line'] !== '' && $u['city'] !== '' && $u['zip'] !== '';
$errors = [];

if (is_post()) {
    check_csrf();
    if (!preg_match('/^\+?[0-9 ()\-]{7,20}$/', $phone)) $errors[] = 'Enter a valid phone number so the carrier can reach you.';
    if (!$hasAddress) $errors[] = 'Add your address before paying.';
    $point = null;
    if ($delivery === 'pickup') {
        foreach ($points as $p) if ((int)$p['id'] === $pointId) $point = $p;
        if (!$point) $errors[] = 'Choose a pick-up point near you.';
        elseif (point_load((int)$point['id']) >= (int)$point['capacity']) $errors[] = $point['name'] . ' just ran out of space. Please choose another point.';
    }
    if (!$errors) {
        $carrierKey = $delivery === 'pickup' ? 'pickup' : $carrier;
        $ship = shipping_cost($carrierKey, $parcel);
        $total = round($base + $ship, 2);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock the items so two buyers can't pay for the same thing.
            $locked = q("SELECT id, status FROM items WHERE id IN ($ph) FOR UPDATE", $ids)->fetchAll();
            foreach ($locked as $l) if ($l['status'] !== 'active') throw new RuntimeException('Sorry, an item was just sold to someone else.');
            q("INSERT INTO orders (buyer_id, seller_id, subtotal, bundle_discount, buyer_fee, shipping_cost, tax, total, delivery_type, carrier, parcel_size,
                 pickup_point_id, ship_name, ship_address, ship_city, ship_state, ship_zip, contact_phone, status, payment_mode, created_at, updated_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending_payment', ?, NOW(), NOW())",
              [$u['id'], $seller['id'], $subtotal, $discount, $fee, $ship, $tax, $total, $delivery, $carrierKey, $parcel,
               $point ? $point['id'] : null, $u['full_name'] ?: $u['username'], $u['address_line'], $u['city'], $u['state'], $u['zip'], $phone,
               stripe_enabled() ? 'stripe' : 'demo']);
            $orderId = (int)$pdo->lastInsertId();
            foreach ($lines as $l) {
                q('INSERT INTO order_items (order_id, item_id, title, price) VALUES (?,?,?,?)', [$orderId, $l['item']['id'], $l['item']['title'], $l['price']]);
            }
            q("UPDATE items SET status = 'reserved' WHERE id IN ($ph)", $ids);   // held while the buyer pays
            if ($phone !== $u['phone']) q('UPDATE users SET phone = ? WHERE id = ?', [$phone, $u['id']]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            if (!$ex instanceof RuntimeException) error_log((string)$ex);
            flash($ex instanceof RuntimeException ? $ex->getMessage() : 'Something went wrong. Please try again.', 'err');
            redirect('index.php');
        }

        if (!stripe_enabled()) {
            // Demo mode: no real payment.
            order_mark_paid($orderId);
            flash('Payment successful! The seller has been notified.');
            redirect('order.php?id=' . $orderId);
        }

        // Stripe Checkout: card, Apple Pay, Google Pay… The webhook marks the order paid.
        $lineItems = [];
        $add = function (string $name, float $amount) use (&$lineItems) {
            if ($amount > 0) $lineItems[] = ['quantity' => 1, 'price_data' => ['currency' => 'usd', 'unit_amount' => cents($amount), 'product_data' => ['name' => $name]]];
        };
        $add(count($lines) === 1 ? $lines[0]['item']['title'] : count($lines) . ' items from ' . $seller['username'] . ($discount > 0 ? ' (bundle discount applied)' : ''), $net);
        $add('Buyer Protection fee', $fee);
        $add('Shipping (' . CARRIERS[$carrierKey]['name'] . ')', $ship);
        if (!STRIPE_AUTOMATIC_TAX) $add('Sales tax', $tax);
        $params = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'customer_email' => $u['email'],
            'client_reference_id' => (string)$orderId,
            'metadata' => ['order_id' => (string)$orderId],
            'payment_intent_data' => ['transfer_group' => 'order_' . $orderId, 'metadata' => ['order_id' => (string)$orderId],
                                      'description' => APP_NAME . ' order #' . $orderId],
            'expires_at' => time() + 31 * 60,
            'success_url' => app_url('order.php?id=' . $orderId . '&paid=1'),
            'cancel_url' => app_url('checkout_cancel.php?order=' . $orderId),
        ];
        if (STRIPE_AUTOMATIC_TAX) { $params['automatic_tax'] = ['enabled' => 'true']; $params['billing_address_collection'] = 'required'; }
        try {
            $session = stripe_request('POST', 'checkout/sessions', $params, 'checkout_order_' . $orderId);
        } catch (StripeError $ex) {
            order_abandon($orderId);
            flash('Payment could not be started: ' . $ex->getMessage(), 'err');
            redirect('checkout.php?items=' . implode(',', $ids));
        }
        q('UPDATE orders SET stripe_session = ? WHERE id = ?', [$session['id'], $orderId]);
        header('Location: ' . $session['url'], true, 303);
        exit;
    }
}

$first = $items[0];
page_start('Checkout', ['back' => true, 'nav' => false]);
?>
<form method="post" id="checkout" data-base="<?= $base ?>" data-pickup="<?= $pickupPrice ?>" data-home="<?= shipping_cost('usps', $parcel) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="items" value="<?= e(implode(',', $ids)) ?>">

  <?php foreach ($lines as $l): $it = $l['item']; ?>
    <div class="co-item">
      <img src="<?= e(photo_url($it['photo'], (string)$it['id'])) ?>" alt="">
      <div class="co-info">
        <div class="co-title"><?= e($it['title']) ?></div>
        <?php if ($it['brand']): ?><div class="muted"><?= e($it['brand']) ?></div><?php endif; ?>
        <div class="muted"><?= e($it['size']) ?></div>
        <div class="co-price"><?= money($l['price']) ?><?php if ($l['price'] !== (float)$it['price']): ?> <s class="muted small"><?= money($it['price']) ?></s><?php endif; ?></div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php foreach ($errors as $er): ?><p class="err pad" style="padding-bottom:0"><?= e($er) ?></p><?php endforeach; ?>

  <h2 class="section-title">Address</h2>
  <a class="box" style="display:flex;gap:12px" href="settings.php?next=<?= urlencode('checkout.php?items=' . implode(',', $ids)) ?>">
    <div style="flex:1">
      <?php if ($hasAddress): ?>
        <div style="font-size:18px"><?= e($u['full_name'] ?: $u['username']) ?></div>
        <div class="muted"><?= e($u['address_line']) ?></div>
        <div class="muted"><?= e(trim($u['zip'] . ' ' . $u['city'] . ' ' . $u['state'])) ?></div>
      <?php else: ?>
        <div class="accent">Add your address</div>
      <?php endif; ?>
    </div>
    <span class="edit-pen"><?= icon('edit', 24) ?></span>
  </a>

  <h2 class="section-title">Delivery option</h2>
  <?php if ($pickupAvailable): ?>
  <label class="option">
    <input type="radio" name="delivery" value="pickup" <?= $delivery === 'pickup' ? 'checked' : '' ?>>
    <?= icon('pin', 26) ?>
    <div class="opt-main"><div class="opt-title">Ship to pick-up point</div><div class="opt-sub"><?= money($pickupPrice) ?></div></div>
    <span class="radio"></span>
  </label>
  <?php else: ?>
  <div class="option" style="cursor:default;opacity:.6">
    <?= icon('pin', 26) ?>
    <div class="opt-main"><div class="opt-title">Ship to pick-up point</div><div class="opt-sub">No pick-up points near your address yet<?= $fullCount ? ' (nearby points are full)' : '' ?>.</div></div>
  </div>
  <?php endif; ?>
  <label class="option">
    <input type="radio" name="delivery" value="home" <?= $delivery === 'home' ? 'checked' : '' ?>>
    <?= icon('home', 26) ?>
    <div class="opt-main"><div class="opt-title">Ship to home</div><div class="opt-sub">From <?= money($homeFrom) ?></div></div>
    <span class="radio"></span>
  </label>

  <h2 class="section-title">Delivery details</h2>
  <div data-show="pickup">
    <p class="muted small" style="margin:0 16px 10px">Pick-up points near <?= e($u['zip'] ?: 'you') ?>, nearest first. Bring a photo ID and the code we send you. Parcels are held for <?= pickup_hold_days() ?> days.</p>
    <?php foreach ($points as $p): ?>
      <label class="option">
        <input type="radio" name="point" value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $pointId ? 'checked' : '' ?>>
        <div class="opt-main">
          <div class="box-line" style="margin-top:0;color:var(--text)"><?= icon('store', 22) ?><span style="flex:1"><?= e($p['name']) ?></span><?php if ($p['miles'] !== null): ?><span class="muted small" style="white-space:nowrap"><?= e(number_format($p['miles'], 1)) ?> mi</span><?php endif; ?></div>
          <div class="box-line"><?= icon('pin', 22) ?><a class="link" href="https://www.google.com/maps/search/?api=1&query=<?= urlencode(point_address($p)) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()"><?= e(point_address($p)) ?></a></div>
          <div class="box-line"><?= icon('clock', 22) ?><?= e(CARRIERS['pickup']['days']) ?><?= $p['hours'] ? ' · ' . e($p['hours']) : '' ?></div>
        </div>
        <span class="radio"></span>
      </label>
    <?php endforeach; ?>
  </div>
  <div data-show="home" hidden>
    <?php foreach (['usps', 'fedex'] as $ck): $c = CARRIERS[$ck]; ?>
      <label class="option">
        <input type="radio" name="carrier" value="<?= $ck ?>" data-price="<?= shipping_cost($ck, $parcel) ?>" <?= $carrier === $ck ? 'checked' : '' ?>>
        <div class="opt-main">
          <div class="box-title"><span class="carrier-logo <?= $ck ?>"><?= e($c['logo']) ?></span><?= e($c['name']) ?></div>
          <div style="font-size:18px;margin-top:8px"><?= money(shipping_cost($ck, $parcel)) ?></div>
          <div class="box-line"><?= icon('clock', 22) ?><?= e($c['days']) ?></div>
        </div>
        <span class="radio"></span>
      </label>
    <?php endforeach; ?>
  </div>

  <h2 class="section-title">Your contact info</h2>
  <div class="pad" style="padding-top:0">
    <input class="input" name="phone" type="tel" value="<?= e($phone) ?>" placeholder="+1 555 010 0100" aria-label="Phone number" required>
    <p class="hint">The carrier uses this to send delivery updates.</p>
  </div>

  <h2 class="section-title">Payment</h2>
  <div class="box">
    <?php if (stripe_enabled()): ?>
      <div class="box-title"><?= icon('lock', 22) ?>Card, Apple Pay or Google Pay</div>
      <div class="box-line"><?= icon('shield', 20) ?>You'll pay on Stripe's secure page. We hold the money until you confirm the item is OK.</div>
      <?php if (stripe_is_test()): ?><div class="box-line accent"><?= icon('info', 20) ?>Test mode: use card 4242 4242 4242 4242, any future date, any CVC.</div><?php endif; ?>
    <?php else: ?>
      <div class="box-title"><?= icon('lock', 22) ?>Demo payment</div>
      <div class="box-line"><?= icon('info', 20) ?>Payments aren't connected on this server, so no card is charged. Paying places the order and notifies the seller.</div>
    <?php endif; ?>
  </div>

  <h2 class="section-title">Price summary</h2>
  <div class="summary">
    <div><span>Order</span><span><?= money($subtotal) ?></span></div>
    <?php if ($discount > 0): ?><div class="discount"><span>Bundle discount (<?= round($rate * 100) ?>%)</span><span>−<?= money($discount) ?></span></div><?php endif; ?>
    <div><span>Buyer Protection fee <a href="help.php#fees" aria-label="About fees"><?= icon('info', 18) ?></a></span><span><?= money($fee) ?></span></div>
    <div><span>Shipping</span><span class="js-ship"><?= money($delivery === 'pickup' ? $pickupPrice : shipping_cost($carrier, $parcel)) ?></span></div>
    <div><span>Sales tax <a href="help.php#fees" aria-label="About sales tax"><?= icon('info', 18) ?></a></span><span><?= money($tax) ?></span></div>
  </div>
  <div style="height:16px"></div>

  <div class="paybar">
    <div class="total"><span>Total to pay</span><span class="js-total"><?= money($base + ($delivery === 'pickup' ? $pickupPrice : shipping_cost($carrier, $parcel))) ?></span></div>
    <button class="btn btn-primary"><?= icon('lock', 18) ?><?= stripe_enabled() ? 'Continue to payment' : 'Pay' ?></button>
    <div class="secure"><?= icon('lock', 15) ?><?= stripe_enabled() ? 'Payments processed securely by Stripe' : 'Demo checkout · no real payment is taken' ?></div>
    <p class="muted tiny" style="text-align:center;margin:8px 0 0">By paying you agree to the <a class="link" href="terms.php">Terms</a>, including Buyer Protection.</p>
  </div>
</form>
<?php page_end();
