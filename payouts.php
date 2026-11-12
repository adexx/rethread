<?php
// Seller payouts via Stripe Connect Express: Stripe collects ID and bank details, we never see them.
require __DIR__ . '/inc/app.php';
$u = require_verified();
$next = safe_next((string)($_GET['next'] ?? 'payouts.php'));

if (!stripe_enabled()) {
    page_start('Payouts', ['back' => 'profile.php', 'tab' => 'profile']);
    echo '<div class="pad"><p class="muted">Payments aren\'t connected on this server yet (demo mode). Earnings appear in your wallet instead.</p><a class="btn btn-outline" href="wallet.php">Open wallet</a></div>';
    page_end();
    exit;
}

// Back from Stripe onboarding: refresh status.
if ($u['stripe_account_id'] && (isset($_GET['return']) || isset($_GET['refresh']))) {
    try {
        stripe_sync_account((int)$u['id'], stripe_request('GET', 'accounts/' . $u['stripe_account_id']));
        $u = q('SELECT * FROM users WHERE id = ?', [$u['id']])->fetch();
        if ($u['payouts_enabled']) { flash('Payouts are set up. You can start selling!'); redirect($next); }
        if (isset($_GET['return'])) flash('Stripe still needs some details before you can get paid.', 'err');
    } catch (StripeError $e) { flash('Could not check your payout status: ' . $e->getMessage(), 'err'); }
}

if (is_post()) {
    check_csrf();
    $act = $_POST['action'] ?? 'onboard';
    try {
        if ($act === 'dashboard' && $u['stripe_account_id']) {
            $link = stripe_request('POST', 'accounts/' . $u['stripe_account_id'] . '/login_links');
            header('Location: ' . $link['url'], true, 303);
            exit;
        }
        if ($u['full_name'] === '' || $u['address_line'] === '') {
            flash('Add your name and address in Settings first.', 'err');
            redirect('settings.php?next=' . urlencode('payouts.php'));
        }
        $acct = stripe_ensure_account($u);
        $link = stripe_request('POST', 'account_links', [
            'account' => $acct,
            'type' => 'account_onboarding',
            'refresh_url' => app_url('payouts.php?refresh=1&next=' . urlencode($next)),
            'return_url' => app_url('payouts.php?return=1&next=' . urlencode($next)),
        ]);
        header('Location: ' . $link['url'], true, 303);
        exit;
    } catch (StripeError $e) {
        flash('Stripe error: ' . $e->getMessage(), 'err');
        redirect('payouts.php');
    }
}

page_start('Payouts', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<div class="pad">
  <?php if ($u['payouts_enabled']): ?>
    <div class="box" style="margin:0 0 16px"><div class="box-title"><?= icon('check', 22) ?>Payouts are set up</div>
      <div class="box-line">When a buyer confirms an order, your money is sent to your bank through Stripe (usually within 2 business days).</div></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="dashboard"><button class="btn btn-outline">View payouts &amp; bank details on Stripe</button></form>
  <?php else: ?>
    <h2 style="font-weight:500;margin-top:0">Get paid for what you sell</h2>
    <p class="muted">We use Stripe to send money to your bank account. Stripe will ask for your name, date of birth, the last 4 digits of your SSN and your bank details, as required by US law. <?= e(APP_NAME) ?> never sees your full SSN or bank details.</p>
    <?php if (stripe_is_test()): ?><p class="accent small">Test mode: use Stripe's test values (e.g. SSN 000-00-0000, routing 110000000, account 000123456789).</p><?php endif; ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="onboard"><button class="btn btn-primary"><?= $u['stripe_account_id'] ? 'Continue setup on Stripe' : 'Set up payouts' ?></button></form>
  <?php endif; ?>
</div>
<?php page_end();
