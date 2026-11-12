<?php
// Background jobs. Run every minute:
//   * * * * * php /path/to/rethread/cron.php >> /var/log/rethread-cron.log 2>&1
// (or over HTTPS with ?token=CRON_TOKEN if your host only offers URL-based cron).
require __DIR__ . '/inc/app.php';

if (PHP_SAPI !== 'cli') {
    if (CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, (string)($_GET['token'] ?? ''))) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain');
}
// Don't let two runs overlap.
$lock = fopen(sys_get_temp_dir() . '/rethread-cron-' . md5(__DIR__) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Already running\n");

$log = function (string $m) { echo date('Y-m-d H:i:s') . ' ' . $m . "\n"; };
$safely = function (string $name, callable $job) use ($log) {
    try { $job(); } catch (Throwable $e) { $log("$name failed: " . $e->getMessage()); error_log("cron $name: " . $e); }
};

// 1. Abandoned checkouts: release items held more than 35 minutes (Stripe also sends checkout.session.expired).
$safely('expire_checkouts', function () use ($log) {
    $ids = q("SELECT id FROM orders WHERE status = 'pending_payment' AND created_at < NOW() - INTERVAL 35 MINUTE")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $o = order_get((int)$id);
        if ($o['payment_mode'] === 'stripe' && $o['stripe_session'] && stripe_enabled()) {
            // Double-check with Stripe so we never release items that were actually paid for.
            $s = stripe_request('GET', 'checkout/sessions/' . $o['stripe_session']);
            if (($s['payment_status'] ?? '') === 'paid') { order_mark_paid((int)$id, ['stripe_payment_intent' => $s['payment_intent']]); continue; }
            if (($s['status'] ?? '') === 'open') { try { stripe_request('POST', 'checkout/sessions/' . $o['stripe_session'] . '/expire'); } catch (StripeError $e) {} }
        }
        order_abandon((int)$id);
        $log("released abandoned checkout #$id");
    }
});

// 2. Remind sellers who haven't shipped after 3 days; cancel and refund after 7.
$safely('ship_deadlines', function () use ($log) {
    foreach (q("SELECT id, seller_id FROM orders WHERE status = 'paid' AND reminder_sent = 0 AND paid_at < NOW() - INTERVAL 3 DAY")->fetchAll() as $o) {
        notify((int)$o['seller_id'], 'Reminder: please ship order #' . $o['id'] . '. It will be canceled and refunded if it isn\'t shipped within 7 days of purchase.', 'order.php?id=' . $o['id']);
        q('UPDATE orders SET reminder_sent = 1 WHERE id = ?', [$o['id']]);
        $log("reminded seller for #{$o['id']}");
    }
    foreach (q("SELECT id FROM orders WHERE status IN ('paid','label_sent') AND paid_at < NOW() - INTERVAL 7 DAY")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        order_refund((int)$id, true, 'system', 'The seller didn\'t ship within 7 days.');
        $log("auto-canceled unshipped #$id");
    }
});

// 3. Auto-complete delivered orders after the Buyer Protection window.
$safely('auto_complete', function () use ($log) {
    $ids = q("SELECT id FROM orders WHERE status = 'delivered' AND delivered_at < NOW() - INTERVAL ? HOUR", [PAYMENT_HOLD_HOURS])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) { order_release((int)$id, 'system'); $log("auto-completed #$id"); }
    // Demo mode has no tracking feed: treat demo parcels as delivered 4 days after shipping.
    if (!shippo_enabled()) {
        // (pick-up orders go to the shop instead and wait there for the buyer.)
        foreach (q("SELECT id FROM orders WHERE status = 'shipped' AND shippo_transaction IS NULL AND shipped_at < NOW() - INTERVAL 4 DAY AND (delivery_type = 'home' OR pickup_point_id IN (SELECT id FROM pickup_points WHERE partner_user_id IS NULL))")->fetchAll(PDO::FETCH_COLUMN) as $id) order_mark_delivered((int)$id);
    }
});

// 3b. Pick-up points: remind buyers 2 days before the deadline; return parcels that weren't collected.
$safely('pickup_deadlines', function () use ($log) {
    foreach (q("SELECT o.id, o.buyer_id, o.pickup_code, o.pickup_deadline, p.name FROM orders o JOIN pickup_points p ON p.id = o.pickup_point_id
                WHERE o.status = 'at_pickup' AND o.pickup_reminded = 0 AND o.pickup_deadline > NOW() AND o.pickup_deadline < NOW() + INTERVAL 2 DAY")->fetchAll() as $o) {
        notify((int)$o['buyer_id'], 'Reminder: your parcel is waiting at ' . $o['name'] . '. Collect it by ' . date('D, M j', strtotime($o['pickup_deadline'])) . ' with code ' . $o['pickup_code'] . ', or it goes back to the seller.', 'order.php?id=' . $o['id']);
        q('UPDATE orders SET pickup_reminded = 1 WHERE id = ?', [$o['id']]);
        $log("pickup reminder #{$o['id']}");
    }
    foreach (q("SELECT id FROM orders WHERE status = 'at_pickup' AND pickup_deadline < NOW()")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        order_return_uncollected((int)$id);
        $log("uncollected, returning #$id");
    }
});

// 4. Send queued email.
$safely('email', function () use ($log) {
    [$ok, $bad] = send_queued_emails(100);
    if ($ok || $bad) $log("emails sent=$ok failed=$bad");
});

// 5. Housekeeping.
$safely('cleanup', function () {
    q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY');
    q('DELETE FROM tokens WHERE expires_at < NOW() - INTERVAL 7 DAY');
    q('DELETE FROM webhook_events WHERE created_at < NOW() - INTERVAL 60 DAY');
    q("DELETE FROM emails WHERE created_at < NOW() - INTERVAL 90 DAY AND status IN ('sent','logged')");
});

q("INSERT INTO settings (k, v) VALUES ('cron_last_run', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)", [(string)time()]);
flock($lock, LOCK_UN);
