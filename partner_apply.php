<?php
// Shops apply to become a pick-up / drop-off point. Admins review applications in Admin > Pick-up points.
require __DIR__ . '/inc/app.php';
$u = require_verified();
$mine = partner_points((int)$u['id']);
$errors = [];
$f = ['name' => '', 'address' => '', 'city' => '', 'state' => '', 'zip' => '', 'hours' => '', 'contact_name' => $u['full_name'], 'contact_phone' => $u['phone'], 'capacity' => '20', 'notes' => ''];
$services = ['pickup' => true, 'dropoff' => true];

if (is_post()) {
    check_csrf();
    foreach ($f as $k => $_) $f[$k] = trim((string)($_POST[$k] ?? ''));
    $f['state'] = strtoupper($f['state']);
    $services = ['pickup' => !empty($_POST['accepts_pickup']), 'dropoff' => !empty($_POST['accepts_dropoff'])];
    $pending = count(array_filter($mine, function ($p) { return $p['status'] === 'pending'; }));
    if ($pending) $errors[] = 'You already have an application under review.';
    if (count($mine) >= 5) $errors[] = 'Contact support to add more locations.';
    if (mb_strlen($f['name']) < 2) $errors[] = 'Enter the shop name.';
    if ($f['address'] === '' || $f['city'] === '') $errors[] = 'Enter the street address and city.';
    if (!preg_match('/^[A-Z]{2}$/', $f['state'])) $errors[] = 'Use the 2-letter state code.';
    if (!preg_match('/^\d{5}$/', $f['zip'])) $errors[] = 'Enter a 5-digit ZIP.';
    if ($f['hours'] === '') $errors[] = 'Enter your opening hours.';
    if ($f['contact_name'] === '' || !preg_match('/^\+?[0-9 ()\-]{7,20}$/', $f['contact_phone'])) $errors[] = 'Enter a contact name and phone number.';
    $cap = (int)$f['capacity'];
    if ($cap < 5 || $cap > 500) $errors[] = 'Capacity must be between 5 and 500 parcels.';
    if (!$services['pickup'] && !$services['dropoff']) $errors[] = 'Choose at least one service.';
    if (empty($_POST['agree'])) $errors[] = 'Please accept the partner agreement.';
    if (!$errors) {
        $ll = geocode_address($f['address'], $f['city'], $f['state'], $f['zip']);
        q("INSERT INTO pickup_points (name, address, city, state, zip, hours, lat, lng, capacity, accepts_pickup, accepts_dropoff, status, partner_user_id,
             contact_name, contact_phone, contact_email, notes, applied_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending', ?, ?, ?, ?, ?, NOW())",
          [mb_substr($f['name'], 0, 120), mb_substr($f['address'], 0, 190), mb_substr($f['city'], 0, 80), $f['state'], $f['zip'], mb_substr($f['hours'], 0, 120),
           $ll[0] ?? null, $ll[1] ?? null, $cap, $services['pickup'] ? 1 : 0, $services['dropoff'] ? 1 : 0, $u['id'],
           mb_substr($f['contact_name'], 0, 120), $f['contact_phone'], $u['email'], mb_substr($f['notes'], 0, 500)]);
        $pid = (int)db()->lastInsertId();
        foreach (q("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $a) {
            notify((int)$a, 'New pick-up point application: ' . $f['name'] . ' (' . $f['city'] . ', ' . $f['state'] . ').', 'admin/pickup.php?tab=pending');
        }
        queue_email($u['email'], 'We received your pick-up point application', mail_layout('Hi ' . $u['username'] . ',', [
            'Thanks for applying to make ' . $f['name'] . ' a ' . APP_NAME . ' pick-up point. We review applications within a few business days and may call you to confirm details.',
        ], ['View your application', app_url('partner.php')]), (int)$u['id'], true);
        flash('Application sent! We\'ll email you when it\'s reviewed.');
        redirect('partner.php');
    }
}

page_start('Become a pick-up point', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<div class="pad">
  <?php if ($mine): ?>
    <div class="box" style="margin:0 0 16px">
      <div class="box-title"><?= icon('store', 22) ?>Your locations</div>
      <?php foreach ($mine as $p): ?><div class="box-line"><?= e($p['name']) ?> · <?= e(ucfirst($p['status'])) ?></div><?php endforeach; ?>
      <a class="btn btn-outline mt-16" href="partner.php">Open partner portal</a>
    </div>
  <?php endif; ?>
  <h2 style="font-weight:500;margin:0 0 6px">Earn money with your shop</h2>
  <p class="muted" style="margin-top:0">Receive parcels for local <?= e(APP_NAME) ?> buyers and accept parcels from sellers. You earn <strong><?= money(pudo_fee()) ?></strong> for every parcel handed over, and new customers come through your door.</p>
  <ul class="muted small" style="padding-left:18px">
    <li>You need a counter that's staffed during opening hours and a safe, dry place to keep parcels.</li>
    <li>Staff use the partner portal on any phone to check parcels in, hand them over with the buyer's code, and accept drop-offs.</li>
    <li>Parcels not collected within <?= pickup_hold_days() ?> days get a return label; hand them back to the carrier.</li>
  </ul>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label class="field"><span>Shop name</span><input class="input" name="name" maxlength="120" value="<?= e($f['name']) ?>" required></label>
    <label class="field"><span>Street address</span><input class="input" name="address" maxlength="190" value="<?= e($f['address']) ?>" autocomplete="street-address" required></label>
    <div class="btn-row" style="grid-template-columns:2fr 1fr 1.3fr">
      <label class="field"><span>City</span><input class="input" name="city" value="<?= e($f['city']) ?>" required></label>
      <label class="field"><span>State</span><input class="input" name="state" maxlength="2" value="<?= e($f['state']) ?>" required></label>
      <label class="field"><span>ZIP</span><input class="input" name="zip" inputmode="numeric" maxlength="5" value="<?= e($f['zip']) ?>" required></label>
    </div>
    <label class="field"><span>Opening hours</span><input class="input" name="hours" maxlength="120" value="<?= e($f['hours']) ?>" placeholder="e.g. Mon–Sat 9am–7pm, Sun 11am–5pm" required></label>
    <div class="btn-row">
      <label class="field"><span>Contact name</span><input class="input" name="contact_name" value="<?= e($f['contact_name']) ?>" required></label>
      <label class="field"><span>Shop phone</span><input class="input" type="tel" name="contact_phone" value="<?= e($f['contact_phone']) ?>" required></label>
    </div>
    <label class="field"><span>How many parcels can you hold at once?</span><input class="input" type="number" name="capacity" min="5" max="500" value="<?= e($f['capacity']) ?>" required></label>
    <div class="checks" style="margin-bottom:14px">
      <label><input type="checkbox" name="accepts_pickup" value="1" <?= $services['pickup'] ? 'checked' : '' ?>>Buyers collect parcels here</label>
      <label><input type="checkbox" name="accepts_dropoff" value="1" <?= $services['dropoff'] ? 'checked' : '' ?>>Sellers drop parcels off here</label>
    </div>
    <label class="field"><span>Anything else? (storage space, parking, accessibility…)</span><textarea class="input" name="notes" maxlength="500"><?= e($f['notes']) ?></textarea></label>
    <label class="row small" style="align-items:flex-start;margin-bottom:16px"><input type="checkbox" name="agree" value="1" required style="margin-top:3px;accent-color:var(--accent)">
      <span>I'm authorised to sign up this business and I accept the <a class="link" href="partner_terms.php" target="_blank">pick-up point partner agreement</a>.</span></label>
    <button class="btn btn-primary">Send application</button>
  </form>
</div>
<?php page_end();
