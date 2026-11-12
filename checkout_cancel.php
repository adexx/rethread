<?php
// Buyer pressed "back" on Stripe's payment page: release the items straight away.
require __DIR__ . '/inc/app.php';
$u = require_login();
$id = (int)($_GET['order'] ?? 0);
$o = q("SELECT * FROM orders WHERE id = ? AND buyer_id = ?", [$id, $u['id']])->fetch();
if ($o && $o['status'] === 'pending_payment') {
    if ($o['stripe_session'] && stripe_enabled()) {
        try { stripe_request('POST', 'checkout/sessions/' . $o['stripe_session'] . '/expire'); }
        catch (StripeError $e) { /* already expired or completed; the webhook has the final say */ }
    }
    // Only release if the payment didn't go through in the meantime.
    if (order_get($id)['status'] === 'pending_payment') order_abandon($id);
    flash('Payment canceled. Nothing was charged.');
}
$first = q('SELECT item_id FROM order_items WHERE order_id = ? LIMIT 1', [$id])->fetchColumn();
redirect($first ? 'item.php?id=' . (int)$first : 'index.php');
