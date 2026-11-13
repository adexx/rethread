<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$errors = [];
$section = '';

if (is_post()) {
    check_csrf();
    $act = (string)($_POST['action'] ?? 'password');
    $section = $act;
    $pwOk = password_verify((string)($_POST['current'] ?? ''), $u['password_hash']);

    if ($act === 'password') {
        $new = (string)($_POST['new'] ?? '');
        if (!$pwOk) $errors[] = 'Current password is incorrect.';
        if (strlen($new) < 10) $errors[] = 'New password must be at least 10 characters.';
        if ($new !== (string)($_POST['confirm'] ?? '')) $errors[] = "New passwords don't match.";
        if (!$errors) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            queue_email($u['email'], 'Your ' . APP_NAME . ' password was changed', mail_layout('Hi ' . $u['username'] . ',', ['Your password was just changed. If this wasn\'t you, reset it right away and contact ' . SUPPORT_EMAIL . '.']), (int)$u['id'], true);
            flash('Password updated.');
            redirect('security.php');
        }
    } elseif ($act === '2fa_start') {
        $_SESSION['totp_setup'] = base32_encode(random_bytes(20));
        redirect('security.php#twofa');
    } elseif ($act === '2fa_confirm' && !empty($_SESSION['totp_setup'])) {
        $secret = $_SESSION['totp_setup'];
        $counter = totp_verify($secret, (string)($_POST['code'] ?? ''));
        if ($counter === null) $errors[] = "That code didn't match. Check your phone's clock is correct and try the newest code.";
        else {
            q('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_counter = ? WHERE id = ?', [$secret, $counter, $u['id']]);
            unset($_SESSION['totp_setup']);
            $_SESSION['2fa_ok'] = true;
            queue_email($u['email'], 'Two-step verification is on', mail_layout('Hi ' . $u['username'] . ',', ['Two-step verification is now on for your account. You\'ll be asked for a code from your authenticator app when you log in.']), (int)$u['id'], true);
            flash('Two-step verification is on.');
            redirect(is_admin() ? 'admin/' : 'security.php');
        }
    } elseif ($act === '2fa_disable') {
        if (!$pwOk) $errors[] = 'Current password is incorrect.';
        elseif (is_admin() && ADMIN_2FA_REQUIRED) $errors[] = 'Admins must keep two-step verification on.';
        else {
            q('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$u['id']]);
            queue_email($u['email'], 'Two-step verification was turned off', mail_layout('Hi ' . $u['username'] . ',', ['Two-step verification was turned off for your account. If this wasn\'t you, change your password now and contact ' . SUPPORT_EMAIL . '.']), (int)$u['id'], true);
            flash('Two-step verification is off.');
            redirect('security.php');
        }
    } elseif ($act === 'export') {
        if (!$pwOk) $errors[] = 'Current password is incorrect.';
        else {
            $id = (int)$u['id'];
            $me = $u;
            foreach (['password_hash', 'totp_secret', 'totp_last_counter'] as $k) unset($me[$k]);
            $data = [
                'exported_at' => date('c'),
                'account' => $me,
                'listings' => q('SELECT id, title, description, brand, size, condition_label, colors, material, price, status, created_at FROM items WHERE user_id = ?', [$id])->fetchAll(),
                'orders' => q('SELECT id, IF(buyer_id = ?, "bought", "sold") side, subtotal, bundle_discount, buyer_fee, shipping_cost, tax, total, status, delivery_type, carrier, tracking, ship_name, ship_address, ship_city, ship_state, ship_zip, contact_phone, created_at FROM orders WHERE buyer_id = ? OR seller_id = ?', [$id, $id, $id])->fetchAll(),
                'messages_sent' => q('SELECT m.conversation_id, m.body, m.offer_amount, m.offer_status, m.created_at FROM messages m WHERE m.sender_id = ?', [$id])->fetchAll(),
                'favorites' => q('SELECT item_id, created_at FROM favorites WHERE user_id = ?', [$id])->fetchAll(),
                'notifications' => q('SELECT body, link, created_at FROM notifications WHERE user_id = ?', [$id])->fetchAll(),
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . APP_NAME . '-data-' . $u['username'] . '-' . date('Ymd') . '.json"');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    } elseif ($act === 'delete') {
        $id = (int)$u['id'];
        $open = (int)q("SELECT COUNT(*) FROM orders WHERE (buyer_id = ? OR seller_id = ?) AND status IN ('pending_payment','paid','label_sent','shipped','delivered','suspended')", [$id, $id])->fetchColumn();
        if (!$pwOk) $errors[] = 'Current password is incorrect.';
        elseif (($_POST['confirm_text'] ?? '') !== 'DELETE') $errors[] = 'Type DELETE to confirm.';
        elseif ($open) $errors[] = "You have $open order" . ($open === 1 ? '' : 's') . ' in progress. Finish or cancel them first.';
        elseif ($u['role'] === 'admin') $errors[] = 'Remove your admin role (ask another admin) before deleting this account.';
        else {
            // Keep orders (needed for accounting and tax records) but strip personal data from the account.
            $pdo = db();
            $pdo->beginTransaction();
            foreach (q('SELECT p.path, p.thumb FROM item_photos p JOIN items i ON i.id = p.item_id WHERE i.user_id = ?', [$id])->fetchAll() as $p) delete_photo_file($p);
            q("DELETE p FROM item_photos p JOIN items i ON i.id = p.item_id WHERE i.user_id = ?", [$id]);
            q("UPDATE items SET status = 'hidden', description = '' WHERE user_id = ? AND status <> 'sold'", [$id]);
            q('DELETE FROM favorites WHERE user_id = ?', [$id]);
            q('DELETE FROM notifications WHERE user_id = ?', [$id]);
            q('DELETE FROM tokens WHERE user_id = ?', [$id]);
            q("UPDATE messages SET body = '[deleted]' WHERE sender_id = ?", [$id]);
            q("UPDATE users SET username = ?, email = ?, password_hash = '', full_name = '', phone = '', address_line = '', city = '', state = '', zip = '', bio = '',
                 totp_secret = NULL, totp_enabled = 0, email_notifications = 0, vacation_mode = 1, deleted_at = NOW() WHERE id = ?",
              ['deleted_' . $id, 'deleted_' . $id . '@deleted.invalid', $id]);
            $pdo->commit();
            $_SESSION = [];
            session_destroy();
            session_start();
            flash('Your account was deleted.');
            redirect('index.php');
        }
    }
}

$setupSecret = $_SESSION['totp_setup'] ?? null;
page_start('Security', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<div class="pad">
  <h2 style="margin:4px 0 6px;font-size:24px">Keep your account secure</h2>
  <p class="muted" style="margin:0 0 20px">Review your info to help protect your account.</p>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>

  <nav class="menu" style="margin:0 -16px 24px">
    <a href="settings.php"><span style="flex:1"><div>Email</div><div class="muted small"><?= e($u['email']) ?> · <?= $u['email_verified_at'] ? 'confirmed' : '<span class="danger">not confirmed</span>' ?></div></span><span class="mi-chev"><?= icon('chevron') ?></span></a>
  </nav>

  <h3 id="twofa" style="margin:0 0 6px;font-weight:500">Two-step verification</h3>
  <?php if ($u['totp_enabled']): ?>
    <p class="muted" style="margin-top:0"><span class="accent">On.</span> You'll enter a code from your authenticator app when you log in.</p>
    <?php if (!(is_admin() && ADMIN_2FA_REQUIRED)): ?>
      <details style="margin-bottom:24px"><summary class="link small" style="cursor:pointer">Turn off</summary>
        <form method="post" class="mt-16"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_disable">
          <label class="field"><span>Current password</span><input class="input" type="password" name="current" autocomplete="current-password" required></label>
          <button class="btn btn-danger">Turn off two-step verification</button>
        </form>
      </details>
    <?php else: ?><p class="note small muted">Required for admins.</p><?php endif; ?>
  <?php elseif ($setupSecret): ?>
    <p class="muted" style="margin-top:0">1. Scan this with an authenticator app (Google Authenticator, 1Password, Authy, Microsoft Authenticator).</p>
    <div id="qr" style="background:#fff;padding:12px;display:inline-block;border-radius:8px" data-uri="<?= e(totp_uri($setupSecret, $u['email'])) ?>"></div>
    <p class="muted small">Can't scan? Enter this key manually: <code style="user-select:all;word-break:break-all"><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></code></p>
    <form method="post" style="margin-bottom:24px"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_confirm">
      <label class="field"><span>2. Enter the 6-digit code it shows</span><input class="input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required style="font-size:22px;letter-spacing:5px"></label>
      <button class="btn btn-primary">Turn on</button>
    </form>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-CNgIRecGo7nphbeZ04Sc13ka07paqdeTu0WR1IM4kNcpmBAUSHSQX0FslNhTDadL4O5SAGapGt4FodqL8My0mA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>(function(){var el=document.getElementById('qr');if(window.QRCode)new QRCode(el,{text:el.dataset.uri,width:180,height:180});})();</script>
  <?php else: ?>
    <p class="muted" style="margin-top:0">Protect your account with a code from your phone as well as your password.<?= is_admin() && ADMIN_2FA_REQUIRED ? ' <strong class="accent">Required to use the admin panel.</strong>' : '' ?></p>
    <form method="post" style="margin-bottom:24px"><?= csrf_field() ?><input type="hidden" name="action" value="2fa_start"><button class="btn btn-outline">Set up two-step verification</button></form>
  <?php endif; ?>

  <form method="post" style="margin-bottom:28px">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <h3 style="margin:0 0 12px;font-weight:500">Change password</h3>
    <label class="field"><span>Current password</span><input class="input" type="password" name="current" autocomplete="current-password" required></label>
    <label class="field"><span>New password (at least 10 characters)</span><input class="input" type="password" name="new" minlength="10" autocomplete="new-password" required></label>
    <label class="field"><span>Confirm new password</span><input class="input" type="password" name="confirm" minlength="10" autocomplete="new-password" required></label>
    <button class="btn btn-primary">Update password</button>
  </form>

  <h3 style="margin:0 0 6px;font-weight:500">Your data</h3>
  <details style="margin-bottom:16px"><summary class="link small" style="cursor:pointer">Download a copy of your data</summary>
    <form method="post" class="mt-16"><?= csrf_field() ?><input type="hidden" name="action" value="export">
      <p class="muted small">Your account details, listings, orders, messages you sent and favorites, as a JSON file.</p>
      <label class="field"><span>Current password</span><input class="input" type="password" name="current" autocomplete="current-password" required></label>
      <button class="btn btn-outline">Download</button>
    </form>
  </details>
  <details <?= $section === 'delete' ? 'open' : '' ?>><summary class="small danger" style="cursor:pointer;text-decoration:underline">Delete my account</summary>
    <form method="post" class="mt-16"><?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <p class="muted small">This removes your profile, listings, photos and favorites and signs you out. It can't be undone. Records of completed orders are kept as required for accounting and tax, without your contact details. Withdraw any wallet balance first.</p>
      <label class="field"><span>Current password</span><input class="input" type="password" name="current" autocomplete="current-password" required></label>
      <label class="field"><span>Type DELETE to confirm</span><input class="input" name="confirm_text" autocomplete="off" required></label>
      <button class="btn btn-danger">Delete my account permanently</button>
    </form>
  </details>
</div>
<?php page_end();
