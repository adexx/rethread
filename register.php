<?php
require __DIR__ . '/inc/app.php';
$next = safe_next((string)($_GET['next'] ?? $_POST['next'] ?? 'index.php'));
if (user()) redirect($next);
$errors = [];
$f = ['username' => '', 'email' => '', 'full_name' => ''];
if (is_post()) {
    check_csrf();
    foreach ($f as $k => $_) $f[$k] = trim((string)($_POST[$k] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (rate_limited('register', '', 10, 60)) $errors[] = 'Too many sign-ups from your network. Please try again later.';
    if (!preg_match('/^[a-zA-Z0-9_.]{3,30}$/', $f['username'])) $errors[] = 'Username: 3–30 letters, numbers, dots or underscores.';
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($f['full_name'] === '') $errors[] = 'Enter your full name (used for shipping).';
    if (strlen($pw) < 10) $errors[] = 'Password must be at least 10 characters.';
    elseif (in_array(strtolower($pw), ['password123', '1234567890', 'qwertyuiop', 'password1234'], true) || stripos($pw, $f['username']) !== false) $errors[] = 'Choose a less guessable password.';
    if (empty($_POST['terms'])) $errors[] = 'You must be 18 or older and accept the Terms and Privacy Policy.';
    if (!$errors && q('SELECT 1 FROM users WHERE username = ? OR email = ?', [$f['username'], $f['email']])->fetchColumn()) $errors[] = 'That username or email is already taken.';
    if (!$errors) {
        record_attempt('register', '', false);   // counts toward the per-IP sign-up limit
        $colors = ['#c46a3a', '#3a7cc4', '#8d4cc2', '#3f8f4f', '#c43a63', '#b0902e', '#3a9aa4'];
        q('INSERT INTO users (username, email, password_hash, full_name, avatar_color, terms_accepted_at, created_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
          [$f['username'], $f['email'], password_hash($pw, PASSWORD_DEFAULT), $f['full_name'], $colors[array_rand($colors)]]);
        $row = q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch();
        send_verification_email($row);
        complete_login($row);
        flash('Welcome to ' . APP_NAME . '! We sent a confirmation link to ' . $f['email'] . '.');
        redirect('verify.php');
    }
}
page_start('Sign up', ['back' => 'login.php', 'nav' => false]);
?>
<form method="post" class="auth">
  <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
  <div class="logo"><b>r</b><?= e(APP_NAME) ?></div>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <label class="field"><span>Username</span><input class="input" name="username" value="<?= e($f['username']) ?>" autocomplete="username" required></label>
  <label class="field"><span>Full name</span><input class="input" name="full_name" value="<?= e($f['full_name']) ?>" autocomplete="name" required></label>
  <label class="field"><span>Email</span><input class="input" type="email" name="email" value="<?= e($f['email']) ?>" autocomplete="email" required></label>
  <label class="field"><span>Password (at least 10 characters)</span><input class="input" type="password" name="password" minlength="10" autocomplete="new-password" required></label>
  <label class="row small" style="align-items:flex-start;margin-bottom:16px"><input type="checkbox" name="terms" value="1" required style="margin-top:3px;accent-color:var(--accent)">
    <span>I'm 18 or older and I agree to the <a class="link" href="terms.php" target="_blank">Terms of Service</a> and <a class="link" href="privacy.php" target="_blank">Privacy Policy</a>.</span></label>
  <button class="btn btn-primary">Create account</button>
  <p class="muted" style="text-align:center">Already have an account? <a class="link" href="login.php?next=<?= urlencode($next) ?>">Log in</a></p>
</form>
<?php page_end();
