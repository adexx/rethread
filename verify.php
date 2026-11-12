<?php
require __DIR__ . '/inc/app.php';

// Link from the email
if (isset($_GET['token'])) {
    $uid = check_token((string)$_GET['token'], 'verify', true);
    if ($uid) {
        q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$uid]);
        flash('Email confirmed. You can now buy, sell and send messages.');
        redirect(user() ? 'index.php' : 'login.php');
    }
    flash('That link has expired or was already used. Log in to get a new one.', 'err');
    redirect(user() ? 'verify.php' : 'login.php');
}

$u = require_login();
if ($u['email_verified_at']) redirect('index.php');
if (is_post()) {
    check_csrf();
    if (rate_limited('verify_resend', (string)$u['id'], 3, 15)) {
        flash('You asked for several links already. Check your spam folder, or try again in 15 minutes.', 'err');
    } else {
        record_attempt('verify_resend', (string)$u['id'], false);
        send_verification_email($u);
        flash('A new link is on its way to ' . $u['email'] . '.');
    }
    redirect('verify.php');
}
page_start('Confirm your email', ['back' => 'index.php']);
?>
<div class="pad" style="text-align:center;padding-top:40px">
  <?= icon('mail', 52) ?>
  <h2 style="font-weight:500">Check your inbox</h2>
  <p class="muted">We sent a link to <strong><?= e($u['email']) ?></strong>. Tap it to confirm your email. You need this to buy, sell and message other members.</p>
  <?php if (MAIL_DRIVER === 'log'): ?><p class="note small muted">Email sending is off on this server (MAIL_DRIVER=log). An admin can read the message in Admin › Emails.</p><?php endif; ?>
  <form method="post" style="margin-top:24px"><?= csrf_field() ?><button class="btn btn-outline">Send a new link</button></form>
  <p class="muted small mt-16">Wrong address? <a class="link" href="settings.php">Change it in Settings</a></p>
</div>
<?php page_end();
