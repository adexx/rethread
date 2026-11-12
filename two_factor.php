<?php
require __DIR__ . '/inc/app.php';
$next = safe_next((string)($_GET['next'] ?? $_POST['next'] ?? 'index.php'));
$uid = (int)($_SESSION['pending_2fa_uid'] ?? 0);
if (!$uid || time() - (int)($_SESSION['pending_2fa_at'] ?? 0) > 600) {
    unset($_SESSION['pending_2fa_uid']);
    flash('Please log in again.', 'err');
    redirect('login.php?next=' . urlencode($next));
}
$row = q('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND banned = 0', [$uid])->fetch();
if (!$row || !$row['totp_enabled']) redirect('login.php');
$error = null;
if (is_post()) {
    check_csrf();
    if (rate_limited('totp', (string)$uid)) {
        $error = 'Too many wrong codes. Wait ' . LOGIN_LOCK_MINUTES . ' minutes.';
    } else {
        $counter = totp_verify($row['totp_secret'], (string)($_POST['code'] ?? ''), (int)$row['totp_last_counter']);
        record_attempt('totp', (string)$uid, $counter !== null);
        if ($counter !== null) {
            q('UPDATE users SET totp_last_counter = ? WHERE id = ?', [$counter, $uid]);
            complete_login($row);
            redirect($next);
        }
        $error = "That code didn't work. Use the current 6-digit code from your authenticator app.";
    }
}
page_start('Two-step verification', ['back' => 'login.php', 'nav' => false]);
?>
<form method="post" class="auth">
  <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="logo"><b>r</b><?= e(APP_NAME) ?></div>
  <p class="muted">Open your authenticator app and enter the 6-digit code for <?= e(APP_NAME) ?>.</p>
  <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
  <label class="field"><span>Code</span><input class="input" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus style="font-size:24px;letter-spacing:6px;text-align:center"></label>
  <button class="btn btn-primary">Verify</button>
  <p class="muted small" style="text-align:center">Lost your phone? Contact <?= e(SUPPORT_EMAIL) ?> from your account email.</p>
</form>
<?php page_end();
