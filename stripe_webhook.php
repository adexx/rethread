<?php
// Stripe sends payment events here. In the Stripe Dashboard > Developers > Webhooks, add an endpoint:
//   URL:    https://YOUR-DOMAIN/stripe_webhook.php
//   Events: checkout.session.completed, checkout.session.async_payment_succeeded,
//           checkout.session.expired, charge.dispute.created, account.updated
//   Tick "Listen to events on Connected accounts" as well (for account.updated).
// Put the endpoint's signing secret (whsec_...) in STRIPE_WEBHOOK_SECRET.
require __DIR__ . '/inc/app.php';

$payload = (string)file_get_contents('php://input');
$event = stripe_verify_webhook($payload, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), STRIPE_WEBHOOK_SECRET);
if (!$event) { http_response_code(400); exit('Bad signature'); }

// Stripe retries; handle each event only once.
try {
    q('INSERT INTO webhook_events (id, provider, type, created_at) VALUES (?, ?, ?, NOW())', [$event['id'], 'stripe', $event['type']]);
} catch (PDOException $e) {
    http_response_code(200); exit('Already processed');
}

$obj = $event['data']['object'] ?? [];
try {
    switch ($event['type']) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            $orderId = (int)($obj['metadata']['order_id'] ?? $obj['client_reference_id'] ?? 0);
            if ($orderId && ($obj['payment_status'] ?? '') === 'paid') {
                $payment = ['stripe_payment_intent' => $obj['payment_intent'] ?? null];
                if (isset($obj['amount_total'])) $payment['total'] = $obj['amount_total'] / 100;
                if (isset($obj['total_details']['amount_tax']) && STRIPE_AUTOMATIC_TAX) $payment['tax'] = $obj['total_details']['amount_tax'] / 100;
                $o = order_get($orderId);
                if ($o && $o['status'] === 'canceled' && !$o['paid_at']) {
                    // Paid after we'd released the items (rare race). Refund rather than sell twice.
                    q("UPDATE orders SET stripe_payment_intent = ? WHERE id = ?", [$payment['stripe_payment_intent'], $orderId]);
                    stripe_request('POST', 'refunds', ['payment_intent' => $payment['stripe_payment_intent']], 'refund_late_' . $orderId);
                    notify((int)$o['buyer_id'], 'Your payment for order #' . $orderId . ' arrived after the checkout expired, so we refunded it in full.', 'index.php');
                } else {
                    order_mark_paid($orderId, $payment);
                }
            }
            break;

        case 'checkout.session.expired':
            $orderId = (int)($obj['metadata']['order_id'] ?? 0);
            if ($orderId) order_abandon($orderId);
            break;

        case 'charge.dispute.created':
            $o = q('SELECT id FROM orders WHERE stripe_payment_intent = ?', [$obj['payment_intent'] ?? ''])->fetch();
            if ($o) {
                order_suspend((int)$o['id'], 'the buyer disputed the payment with their bank.');
                foreach (q("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $a) {
                    notify((int)$a, 'Chargeback opened on order #' . $o['id'] . '. Respond in the Stripe Dashboard.', 'admin/order.php?id=' . $o['id']);
                }
            }
            break;

        case 'account.updated':
            $uid = (int)(q('SELECT id FROM users WHERE stripe_account_id = ?', [$obj['id'] ?? ''])->fetchColumn() ?: 0);
            if ($uid) stripe_sync_account($uid, $obj);
            break;
    }
} catch (Throwable $e) {
    // Let Stripe retry later.
    error_log('Stripe webhook ' . $event['type'] . ' failed: ' . $e->getMessage());
    q('DELETE FROM webhook_events WHERE id = ?', [$event['id']]);
    http_response_code(500);
    exit('Error');
}
http_response_code(200);
echo 'ok';
