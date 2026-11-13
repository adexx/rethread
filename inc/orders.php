<?php
// Every order state change goes through these functions, so money moves the same way
// whether a buyer, seller, admin, webhook or cron job triggers it.

function order_get(int $id, bool $lock = false): ?array
{
    return q('SELECT * FROM orders WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch() ?: null;
}

function order_title(array $o): string
{
    $t = q('SELECT title FROM order_items WHERE order_id = ? ORDER BY id LIMIT 1', [$o['id']])->fetchColumn();
    $n = (int)q('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [$o['id']])->fetchColumn();
    return '"' . $t . '"' . ($n > 1 ? ' + ' . ($n - 1) . ' more' : '');
}

function seller_payout(array $o): float
{
    return round((float)$o['subtotal'] - (float)$o['bundle_discount'], 2);
}

/** pending_payment -> paid. Called by the Stripe webhook (or directly in demo mode). Idempotent. */
function order_mark_paid(int $id, array $payment = []): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    $o = order_get($id, true);
    if (!$o || $o['status'] !== 'pending_payment') { $pdo->commit(); return false; }
    $sets = ["status = 'paid'", 'paid_at = NOW()', 'updated_at = NOW()'];
    $params = [];
    foreach (['stripe_payment_intent', 'stripe_charge', 'tax', 'total'] as $k) {
        if (array_key_exists($k, $payment)) { $sets[] = "$k = ?"; $params[] = $payment[$k]; }
    }
    $params[] = $id;
    q('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    q("UPDATE items SET status = 'sold' WHERE id IN (SELECT item_id FROM order_items WHERE order_id = ?)", [$id]);
    $pdo->commit();
    $buyer = q('SELECT username FROM users WHERE id = ?', [$o['buyer_id']])->fetchColumn();
    notify((int)$o['seller_id'], $buyer . ' bought ' . order_title($o) . '. Get the shipping label and send it within 5 days.', 'order.php?id=' . $id);
    notify((int)$o['buyer_id'], 'Payment successful for ' . order_title($o) . '. The seller has 5 days to ship.', 'order.php?id=' . $id);
    return true;
}

/** Abandoned or expired checkout: put the items back on sale. */
function order_abandon(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $o = order_get($id, true);
    if ($o && $o['status'] === 'pending_payment') {
        q("UPDATE orders SET status = 'canceled', updated_at = NOW() WHERE id = ?", [$id]);
        q("UPDATE items SET status = 'active' WHERE status = 'reserved' AND id IN (SELECT item_id FROM order_items WHERE order_id = ?)", [$id]);
    }
    $pdo->commit();
}

/** Pays the seller and closes the order. Throws on payment-provider errors (order unchanged). */
function order_release(int $id, string $who, string $note = ''): void
{
    $o = order_get($id);
    if (!$o || !in_array($o['status'], ['shipped', 'at_pickup', 'delivered', 'suspended'], true)) throw new RuntimeException("This order can't be completed now.");
    $payout = seller_payout($o);
    $transfer = $o['stripe_transfer'];
    if ($o['payment_mode'] === 'stripe' && !$transfer) {
        $seller = q('SELECT stripe_account_id FROM users WHERE id = ?', [$o['seller_id']])->fetch();
        if (empty($seller['stripe_account_id'])) throw new RuntimeException("The seller hasn't set up payouts, so the money can't be sent yet.");
        $charge = $o['stripe_charge'];
        if (!$charge && $o['stripe_payment_intent']) {
            $pi = stripe_request('GET', 'payment_intents/' . $o['stripe_payment_intent']);
            $charge = is_array($pi['latest_charge'] ?? null) ? $pi['latest_charge']['id'] : ($pi['latest_charge'] ?? null);
        }
        $params = ['amount' => cents($payout), 'currency' => 'usd', 'destination' => $seller['stripe_account_id'],
                   'transfer_group' => 'order_' . $id, 'metadata' => ['order_id' => (string)$id]];
        if ($charge) $params['source_transaction'] = $charge;   // lets the transfer use funds that haven't settled yet
        $t = stripe_request('POST', 'transfers', $params, 'transfer_order_' . $id);
        $transfer = $t['id'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    $locked = order_get($id, true);
    if ($locked['status'] === 'completed') { $pdo->commit(); return; }
    q("UPDATE orders SET status = 'completed', stripe_transfer = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?", [$transfer, $id]);
    q('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?', [$payout, $o['seller_id']]);
    $pdo->commit();
    $extra = $note !== '' ? ' Note: ' . $note : '';
    notify((int)$o['seller_id'], 'Order #' . $id . ' is complete. ' . money($payout) . ($o['payment_mode'] === 'stripe' ? ' is on its way to your bank via Stripe.' : ' was added to your wallet.') . $extra, 'wallet.php');
    if ($who !== 'buyer') notify((int)$o['buyer_id'], 'Order #' . $id . ' is complete and the seller has been paid.' . $extra, 'order.php?id=' . $id);
    // Ask both sides for a review (in-app only; the completion email above is enough email).
    notify((int)$o['buyer_id'], 'How was your order #' . $id . '? Leave the seller a review.', 'order.php?id=' . $id . '#review', false);
    notify((int)$o['seller_id'], 'Rate the buyer of order #' . $id . '.', 'order.php?id=' . $id . '#review', false);
}

/** Cancels and refunds the buyer in full. $relist puts the items back on sale (otherwise hidden). */
function order_refund(int $id, bool $relist, string $who, string $note = ''): void
{
    $o = order_get($id);
    if (!$o || !in_array($o['status'], ['paid', 'label_sent', 'shipped', 'at_pickup', 'delivered', 'suspended'], true)) throw new RuntimeException("This order can't be refunded now.");
    $refund = $o['stripe_refund'];
    if ($o['payment_mode'] === 'stripe' && !$refund && $o['stripe_payment_intent']) {
        $r = stripe_request('POST', 'refunds', ['payment_intent' => $o['stripe_payment_intent'], 'reason' => 'requested_by_customer',
                                                'metadata' => ['order_id' => (string)$id]], 'refund_order_' . $id);
        $refund = $r['id'];
    }
    if ($o['shippo_transaction'] && $o['status'] === 'label_sent') shippo_refund_label($o['shippo_transaction']);
    $pdo = db();
    $pdo->beginTransaction();
    q("UPDATE orders SET status = 'canceled', stripe_refund = ?, updated_at = NOW() WHERE id = ?", [$refund, $id]);
    q('UPDATE items SET status = ? WHERE id IN (SELECT item_id FROM order_items WHERE order_id = ?)', [$relist ? 'active' : 'hidden', $id]);
    $pdo->commit();
    $extra = $note !== '' ? ' Note: ' . $note : '';
    $by = ['buyer' => 'You canceled', 'seller' => 'The seller canceled', 'admin' => 'Support canceled', 'system' => 'We canceled'][$who] ?? 'We canceled';
    notify((int)$o['buyer_id'], $by . ' order #' . $id . '. ' . money($o['total']) . ' is being refunded to your original payment method.' . $extra, 'order.php?id=' . $id);
    $byS = ['buyer' => 'The buyer canceled', 'seller' => 'You canceled', 'admin' => 'Support canceled', 'system' => 'We canceled'][$who] ?? 'We canceled';
    notify((int)$o['seller_id'], $byS . ' order #' . $id . '. ' . ($relist ? 'The items are back on sale.' : 'The items are hidden; you can show them again from the listing.') . $extra, 'order.php?id=' . $id);
}

function order_suspend(int $id, string $reason): void
{
    $o = order_get($id);
    if (!$o || !in_array($o['status'], ['paid', 'label_sent', 'shipped', 'at_pickup', 'delivered'], true)) return;
    q("UPDATE orders SET status = 'suspended', updated_at = NOW() WHERE id = ?", [$id]);
    foreach ([$o['buyer_id'], $o['seller_id']] as $uid) notify((int)$uid, 'Order #' . $id . ' is on hold: ' . $reason . ' Support will review it; the payment is held until then.', 'order.php?id=' . $id);
}

function order_mark_shipped(int $id): void
{
    $o = order_get($id);
    if (!$o || $o['status'] !== 'label_sent') return;
    q("UPDATE orders SET status = 'shipped', shipped_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
    notify((int)$o['buyer_id'], 'Your order ' . order_title($o) . ' is shipped and on its way!', 'order.php?id=' . $id);
}

function order_mark_delivered(int $id): void
{
    $o = order_get($id);
    if ($o && $o['delivery_type'] === 'pickup') { order_mark_at_point($id); return; }   // carrier delivered it to the shop, not the buyer
    if (!$o || !in_array($o['status'], ['label_sent', 'shipped'], true)) return;
    q("UPDATE orders SET status = 'delivered', delivered_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
    notify((int)$o['buyer_id'], 'Your order ' . order_title($o) . ' was delivered. Check it and tap "Everything is OK", or report a problem within ' . PAYMENT_HOLD_HOURS . ' hours.', 'order.php?id=' . $id);
    notify((int)$o['seller_id'], 'Order #' . $id . ' was delivered. You\'ll be paid once the buyer confirms, or automatically after ' . PAYMENT_HOLD_HOURS . ' hours.', 'order.php?id=' . $id);
}
