<?php
require __DIR__ . '/inc/app.php';
if (user()) redirect('security.php');
$sent = false;
$error = null;
if (is_post()) {
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    if (rate_limited('forgot', $email, 3, 30)) {
        $error = 'Too many requests. Please wait a while and try again.';
    } else {
        record_attempt('forgot', $email, false);
        $row = q('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL AND banned = 0', [$email])->fetch();
        if ($row) send_password_reset_email($row);
        $sent = true;   // same answer either way, so this page can't be used to find out who has an account
    }
}
page_start('Forgot password', ['back' => 'login.php', 'nav' => false]);
?>
<div class="auth">
  <?php if ($sent): ?>
    <h2 style="font-weight:500">Check your email</h2>
    <p class="muted">If an account uses that address, we've sent a link to reset the password. It expires in 1 hour.</p>
    <a class="btn btn-outline" href="login.php">Back to log in</a>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <h2 style="font-weight:500;margin-top:0">Reset your password</h2>
      <p class="muted">Enter the email you signed up with and we'll send you a link.</p>
      <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
      <label class="field"><span>Email</span><input class="input" type="email" name="email" autocomplete="email" required autofocus></label>
      <button class="btn btn-primary">Send reset link</button>
    </form>
  <?php endif; ?>
</div>
<?php page_end();
