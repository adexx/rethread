<?php
require __DIR__ . '/inc/app.php';
$next = safe_next((string)($_GET['next'] ?? $_POST['next'] ?? 'index.php'));
if (user()) redirect($next);
$error = null;
$email = '';
if (is_post()) {
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    if (rate_limited('login', $email)) {
        $error = 'Too many failed attempts. Wait ' . LOGIN_LOCK_MINUTES . ' minutes or reset your password.';
    } else {
        $row = q('SELECT * FROM users WHERE (email = ? OR username = ?) AND deleted_at IS NULL', [$email, $email])->fetch();
        // Verify against a dummy hash when the user doesn't exist, so timing doesn't reveal valid emails.
        $ok = password_verify((string)($_POST['password'] ?? ''), $row['password_hash'] ?? '$2y$10$jl3vzOuPAXKWkbCSCaz6j.nx5cz74vCDp45ULyD1vhTI1/nHsI6VS');
        record_attempt('login', $email, $ok && $row);
        if ($ok && $row && $row['banned']) {
            $error = 'This account has been suspended. Contact ' . SUPPORT_EMAIL . '.';
        } elseif ($ok && $row) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $row['id']]);
            if ($row['totp_enabled']) {
                session_regenerate_id(true);
                $_SESSION['pending_2fa_uid'] = (int)$row['id'];
                $_SESSION['pending_2fa_at'] = time();
                redirect('two_factor.php?next=' . urlencode($next));
            }
            complete_login($row);
            redirect($next);
        } else {
            $error = 'Wrong email or password.';
        }
    }
}
page_start('Log in', ['back' => 'index.php', 'nav' => false]);
?>
<form method="post" class="auth">
  <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="logo"><b>r</b><?= e(APP_NAME) ?></div>
  <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
  <label class="field"><span>Email or username</span><input class="input" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></label>
  <label class="field"><span>Password</span><input class="input" type="password" name="password" autocomplete="current-password" required></label>
  <button class="btn btn-primary">Log in</button>
  <p style="text-align:center"><a class="link small" href="forgot.php">Forgot your password?</a></p>
  <p class="muted" style="text-align:center">New here? <a class="link" href="register.php?next=<?= urlencode($next) ?>">Create an account</a></p>
  <?php if (!IS_PRODUCTION): ?><p class="muted small" style="text-align:center">Demo accounts are listed in README.md.</p><?php endif; ?>
</form>
<?php page_end();
