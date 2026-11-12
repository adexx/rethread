<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$next = safe_next((string)($_GET['next'] ?? $_POST['next'] ?? ''));
$hasNext = !empty($_GET['next']) || !empty($_POST['next']);
$f = $u;
$errors = [];
if (is_post()) {
    check_csrf();
    foreach (['full_name', 'email', 'phone', 'address_line', 'city', 'state', 'zip', 'bio'] as $k) $f[$k] = trim((string)($_POST[$k] ?? ''));
    $f['email_notifications'] = !empty($_POST['email_notifications']) ? 1 : 0;
    $emailChanged = strcasecmp($f['email'], $u['email']) !== 0;
    $f['state'] = strtoupper($f['state']);
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    elseif (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$f['email'], $u['id']])->fetchColumn()) $errors[] = 'That email is already used by another account.';
    if ($f['phone'] !== '' && !preg_match('/^\+?[0-9 ()\-]{7,20}$/', $f['phone'])) $errors[] = 'Enter a valid phone number.';
    if ($f['zip'] !== '' && !preg_match('/^\d{5}(-\d{4})?$/', $f['zip'])) $errors[] = 'Enter a 5-digit ZIP code.';
    if ($f['state'] !== '' && !preg_match('/^[A-Z]{2}$/', $f['state'])) $errors[] = 'Use the 2-letter state code, e.g. OR.';
    if (mb_strlen($f['bio']) > 500) $errors[] = 'Bio must be under 500 characters.';
    if (!$errors) {
        q('UPDATE users SET full_name=?, email=?, phone=?, address_line=?, city=?, state=?, zip=?, bio=?, email_notifications=? WHERE id = ?',
          [$f['full_name'], $f['email'], $f['phone'], $f['address_line'], $f['city'], $f['state'], $f['zip'], $f['bio'], $f['email_notifications'], $u['id']]);
        $addrChanged = $f['address_line'] !== $u['address_line'] || $f['city'] !== $u['city'] || $f['state'] !== $u['state'] || $f['zip'] !== $u['zip'];
        if ($addrChanged) geocode_user(array_merge($u, $f));   // for nearest pick-up points
        if ($emailChanged) {
            // New address must be confirmed again; tell the old address in case this wasn't the owner.
            q('UPDATE users SET email_verified_at = NULL WHERE id = ?', [$u['id']]);
            queue_email($u['email'], 'Your ' . APP_NAME . ' email was changed', mail_layout('Hi ' . $u['username'] . ',', ['The email on your account was changed to ' . $f['email'] . '. If this wasn\'t you, contact ' . SUPPORT_EMAIL . ' right away.']), (int)$u['id'], true);
            send_verification_email(array_merge($u, ['email' => $f['email']]));
            flash('Saved. Check ' . $f['email'] . ' for a link to confirm your new address.');
            redirect('verify.php');
        }
        flash('Settings saved.');
        redirect($hasNext ? $next : 'settings.php');
    }
}
page_start('Settings', ['back' => $hasNext ? $next : 'profile.php', 'tab' => 'profile']);
?>
<form method="post" class="pad">
  <?= csrf_field() ?>
  <?php if ($hasNext): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <h2 class="section-title" style="margin:0 0 12px">Your address</h2>
  <p class="muted small" style="margin-top:-6px">Where couriers will deliver orders, and what we'll use to process returns.</p>
  <label class="field"><span>Full name</span><input class="input" name="full_name" value="<?= e($f['full_name']) ?>" autocomplete="name" required></label>
  <label class="field"><span>Street address</span><input class="input" name="address_line" value="<?= e($f['address_line']) ?>" autocomplete="street-address"></label>
  <div class="btn-row" style="grid-template-columns:2fr 1fr 1.3fr">
    <label class="field"><span>City</span><input class="input" name="city" value="<?= e($f['city']) ?>" autocomplete="address-level2"></label>
    <label class="field"><span>State</span><input class="input" name="state" maxlength="2" value="<?= e($f['state']) ?>" autocomplete="address-level1"></label>
    <label class="field"><span>ZIP</span><input class="input" name="zip" inputmode="numeric" value="<?= e($f['zip']) ?>" autocomplete="postal-code"></label>
  </div>
  <h2 class="section-title" style="margin:12px 0">Account</h2>
  <label class="field"><span>Email</span><input class="input" type="email" name="email" value="<?= e($f['email']) ?>" autocomplete="email" required></label>
  <label class="field"><span>Phone</span><input class="input" type="tel" name="phone" value="<?= e($f['phone']) ?>" autocomplete="tel"></label>
  <label class="field"><span>About you</span><textarea class="input" name="bio" maxlength="500"><?= e($f['bio']) ?></textarea></label>
  <label class="row" style="justify-content:space-between;margin:4px 0 18px;cursor:pointer"><span>Email me about orders, offers and messages</span>
    <span class="switch"><input type="checkbox" name="email_notifications" value="1" <?= $f['email_notifications'] ? 'checked' : '' ?>><span></span></span></label>
  <button class="btn btn-primary">Save</button>
</form>
<?php page_end();
