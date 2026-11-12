<?php
require __DIR__ . '/inc/app.php';
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$uid = check_token($token, 'reset', false);
$errors = [];
if ($uid && is_post()) {
    check_csrf();
    $pw = (string)($_POST['password'] ?? '');
    if (strlen($pw) < 10) $errors[] = 'Password must be at least 10 characters.';
    if ($pw !== (string)($_POST['confirm'] ?? '')) $errors[] = "Passwords don't match.";
    if (!$errors && check_token($token, 'reset', true)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $uid]);
        q("UPDATE tokens SET used_at = NOW() WHERE user_id = ? AND type = 'reset' AND used_at IS NULL", [$uid]);
        q("DELETE FROM login_attempts WHERE action = 'login' AND identifier IN (SELECT LOWER(email) FROM users WHERE id = ?)", [$uid]);
        $email = q('SELECT email, username FROM users WHERE id = ?', [$uid])->fetch();
        queue_email($email['email'], 'Your ' . APP_NAME . ' password was changed', mail_layout('Hi ' . $email['username'] . ',', ['Your password was just changed. If this wasn\'t you, reset it again right away and contact ' . SUPPORT_EMAIL . '.']), $uid, true);
        unset($_SESSION['uid']);
        session_regenerate_id(true);
        flash('Password updated. Log in with your new password.');
        redirect('login.php');
    }
}
page_start('New password', ['back' => 'login.php', 'nav' => false]);
?>
<div class="auth">
  <?php if (!$uid): ?>
    <h2 style="font-weight:500;margin-top:0">Link expired</h2>
    <p class="muted">This reset link is invalid, expired or already used.</p>
    <a class="btn btn-primary" href="forgot.php">Get a new link</a>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
      <h2 style="font-weight:500;margin-top:0">Choose a new password</h2>
      <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
      <label class="field"><span>New password (at least 10 characters)</span><input class="input" type="password" name="password" minlength="10" autocomplete="new-password" required autofocus></label>
      <label class="field"><span>Confirm</span><input class="input" type="password" name="confirm" minlength="10" autocomplete="new-password" required></label>
      <button class="btn btn-primary">Save password</button>
    </form>
  <?php endif; ?>
</div>
<?php page_end();
