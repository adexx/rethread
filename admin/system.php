<?php
// Go-live checklist: reads the real configuration and tells you what's still missing.
require __DIR__ . '/_inc.php';
require_admin();

$lastCron = (string)setting('cron_last_run', '');
$cronAge = $lastCron !== '' ? time() - (int)$lastCron : null;
$lastBackup = null;
foreach (glob(__DIR__ . '/../backups/*.sql.gz') ?: [] as $f) $lastBackup = max((int)$lastBackup, (int)filemtime($f));
$adminsNo2fa = (int)q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND totp_enabled = 0")->fetchColumn();
$defaultAdmin = q("SELECT password_hash FROM users WHERE email = 'admin@example.com'")->fetchColumn();
$demoUsers = (int)q("SELECT COUNT(*) FROM users WHERE email LIKE '%@example.com' AND deleted_at IS NULL")->fetchColumn();
$installExists = is_file(__DIR__ . '/../install.php');

$checks = [
    'Server' => [
        ['APP_ENV is production', IS_PRODUCTION, 'Set APP_ENV=production: hides error details from visitors and locks the installer.'],
        ['Served over HTTPS', is_https(), 'Get a TLS certificate (most hosts offer free Let\'s Encrypt) and set FORCE_HTTPS=true.'],
        ['APP_URL uses https://', strpos(APP_URL, 'https://') === 0, 'Set APP_URL to your real https:// address; it\'s used in emails and Stripe redirects.'],
        ['Database password changed from default', DB_PASS !== 'root' && DB_USER !== 'root', 'Create a dedicated MySQL user with a strong password; don\'t use root/root.'],
        ['PHP 8.1 or newer', version_compare(PHP_VERSION, '8.1', '>='), 'Current: ' . PHP_VERSION],
        ['Installer removed from the server', !$installExists || IS_PRODUCTION, 'Delete install.php after setup (it is blocked in production, but removing it is safer).'],
        ['Uploads folder writable', is_writable(UPLOAD_DIR), 'The web server must be able to write to uploads/.'],
    ],
    'Payments & shipping' => [
        ['Stripe connected', stripe_enabled(), 'Set STRIPE_SECRET_KEY (start with sk_test_…).'],
        ['Stripe in live mode', stripe_enabled() && !stripe_is_test(), 'Switch to sk_live_… keys only after testing the whole flow in test mode.'],
        ['Stripe webhook secret set', STRIPE_WEBHOOK_SECRET !== '', 'Add the webhook endpoint in Stripe and copy its signing secret into STRIPE_WEBHOOK_SECRET.'],
        ['Shippo connected', shippo_enabled(), 'Set SHIPPO_API_KEY to print real labels.'],
        ['Shippo webhook token set', SHIPPO_WEBHOOK_TOKEN !== '', 'Set SHIPPO_WEBHOOK_TOKEN and add the webhook URL in Shippo.'],
    ],
    'Email' => [
        ['Email sending enabled', MAIL_DRIVER === 'smtp' && SMTP_HOST !== '', 'Set MAIL_DRIVER=smtp and the SMTP_* settings (Postmark, Amazon SES, Mailgun, SendGrid…).'],
        ['From address on your own domain', strpos(MAIL_FROM, '@example.com') === false, 'Use an address on your domain and set up SPF/DKIM with your email provider.'],
        ['No failed emails in the last day', !(int)q("SELECT COUNT(*) FROM emails WHERE status = 'failed' AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn(), 'See Email outbox for errors.'],
    ],
    'Jobs & backups' => [
        ['Cron ran in the last 10 minutes', $cronAge !== null && $cronAge < 600, $cronAge === null ? 'cron.php has never run. Add it to crontab (see DEPLOY.md).' : 'Last run ' . round($cronAge / 60) . ' minutes ago.'],
        ['Database backup in the last 26 hours', $lastBackup && time() - $lastBackup < 26 * 3600, 'Schedule scripts/backup.sh daily and copy backups off the server.'],
    ],
    'Accounts' => [
        ['All admins use two-step verification', $adminsNo2fa === 0, "$adminsNo2fa admin(s) without 2FA."],
        ['Default admin password changed', !$defaultAdmin || !password_verify('admin1234', (string)$defaultAdmin), 'Change the admin@example.com password, or better, create your own admin and delete it.'],
        ['Demo accounts removed', $demoUsers === 0, "$demoUsers @example.com accounts exist. Start production from an empty database (install.php without demo data)."],
    ],
    'Business & legal' => [
        ['Company details filled in', COMPANY_NAME !== 'Rethread LLC' && strpos(SUPPORT_EMAIL, 'example.com') === false, 'Set COMPANY_NAME, COMPANY_ADDRESS, SUPPORT_EMAIL, GOVERNING_STATE.'],
        ['Legal pages reviewed by a lawyer', LEGAL_REVIEWED, 'The Terms, Privacy Policy and prohibited items pages are starting drafts. Have them reviewed, then set LEGAL_REVIEWED=true.'],
        ['Sales tax handled', STRIPE_AUTOMATIC_TAX || tax_rate() > 0, 'Marketplaces usually must collect sales tax in most US states. Enable Stripe Tax (STRIPE_AUTOMATIC_TAX=true) or get advice.'],
    ],
];
$done = $total = 0;
foreach ($checks as $group) foreach ($group as $c) { $total++; if ($c[1]) $done++; }

admin_start('Go-live checklist', 'system');
?>
<div class="tiles">
  <div class="tile<?= $done < $total ? ' alert' : '' ?>"><div class="t-label">Ready</div><div class="t-value"><?= $done ?> / <?= $total ?></div><div class="t-sub"><?= $done === $total ? 'All checks pass' : 'Fix the red items before taking real money' ?></div></div>
  <div class="tile"><div class="t-label">Payments</div><div class="t-value" style="font-size:20px"><?= stripe_enabled() ? (stripe_is_test() ? 'Stripe (test)' : 'Stripe (LIVE)') : 'Demo' ?></div></div>
  <div class="tile"><div class="t-label">Shipping labels</div><div class="t-value" style="font-size:20px"><?= shippo_enabled() ? (strpos(SHIPPO_API_KEY, 'shippo_test_') === 0 ? 'Shippo (test)' : 'Shippo (LIVE)') : 'Demo' ?></div></div>
  <div class="tile"><div class="t-label">Email</div><div class="t-value" style="font-size:20px"><?= MAIL_DRIVER === 'smtp' ? 'SMTP' : 'Log only' ?></div></div>
</div>
<div class="cols">
<?php foreach ($checks as $group => $items): ?>
  <section class="panel">
    <h2><?= e($group) ?></h2>
    <table class="tbl"><tbody>
      <?php foreach ($items as [$label, $ok, $help]): ?>
        <tr><td style="width:28px"><?= $ok ? '<span class="badge badge-good">✓</span>' : '<span class="badge badge-bad">✗</span>' ?></td>
          <td><?= e($label) ?><?php if (!$ok): ?><div class="note"><?= e($help) ?></div><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </section>
<?php endforeach; ?>
</div>
<p class="note">Webhook URLs to register: <code><?= e(app_url('stripe_webhook.php')) ?></code> (Stripe) and <code><?= e(app_url('shippo_webhook.php?token=…')) ?></code> (Shippo). Full steps are in DEPLOY.md.</p>
<?php admin_end();
