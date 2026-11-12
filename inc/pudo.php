<?php
// Pick-up / drop-off (PUDO) network: partner shops that receive parcels for buyers
// and accept parcels from sellers.
//
// Parcel journey for a pick-up order:
//   paid -> label_sent -> shipped -> at_pickup (shop received it, buyer gets a code)
//        -> delivered (buyer collected it with the code; Buyer Protection window starts)
//        -> completed
// Not collected within the hold period -> buyer refunded, shop returns the parcel to the seller.

const PICKUP_SEARCH_MILES = 30;

function pickup_hold_days(): int { return max(2, (int)setting('pickup_hold_days', 7)); }
function pudo_fee(): float { return (float)setting('pudo_fee', 0.40); }

// ---------- Geocoding (US Census Bureau: free, no key, US addresses only) ----------
/** @return array{0: float, 1: float}|null [lat, lng] */
function geocode_address(string $street, string $city, string $state, string $zip): ?array
{
    if (trim($street) === '' || (trim($zip) === '' && trim($city) === '')) return null;
    $url = 'https://geocoding.geo.census.gov/geocoder/locations/address?' . http_build_query([
        'street' => $street, 'city' => $city, 'state' => $state, 'zip' => $zip,
        'benchmark' => 'Public_AR_Current', 'format' => 'json',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
                            CURLOPT_USERAGENT => APP_NAME . ' address lookup']);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$raw, true);
    $c = $data['result']['addressMatches'][0]['coordinates'] ?? null;
    return $c ? [(float)$c['y'], (float)$c['x']] : null;
}

function miles_between(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Stores coordinates for a member's address (call after the address changes). */
function geocode_user(array $u): ?array
{
    $ll = geocode_address($u['address_line'], $u['city'], $u['state'], $u['zip']);
    q('UPDATE users SET lat = ?, lng = ?, geocoded_at = NOW() WHERE id = ?', [$ll[0] ?? null, $ll[1] ?? null, $u['id']]);
    return $ll;
}

function user_coords(array $u): ?array
{
    if ($u['lat'] !== null && $u['lng'] !== null) return [(float)$u['lat'], (float)$u['lng']];
    // Try once per day at most, so a bad address doesn't slow down every checkout.
    if ($u['address_line'] !== '' && (!$u['geocoded_at'] || strtotime($u['geocoded_at']) < time() - 86400)) return geocode_user($u);
    return null;
}

/** Parcels currently assigned to a point (in transit to it, or waiting there). */
function point_load(int $pointId): int
{
    return (int)q("SELECT COUNT(*) FROM orders WHERE pickup_point_id = ? AND status IN ('paid','label_sent','shipped','at_pickup')", [$pointId])->fetchColumn();
}

/**
 * Active points near a member, nearest first, each with 'miles' (or null) and 'full'.
 * $mode: 'pickup' (buyer collects) or 'dropoff' (seller hands in).
 */
function points_near(array $u, string $mode = 'pickup', int $limit = 8): array
{
    $col = $mode === 'dropoff' ? 'accepts_dropoff' : 'accepts_pickup';
    $points = q("SELECT p.*, (SELECT COUNT(*) FROM orders o WHERE o.pickup_point_id = p.id AND o.status IN ('paid','label_sent','shipped','at_pickup')) AS load_now
                 FROM pickup_points p WHERE p.status = 'active' AND p.$col = 1")->fetchAll();
    $ll = user_coords($u);
    foreach ($points as &$p) {
        $p['full'] = $mode === 'pickup' && (int)$p['load_now'] >= (int)$p['capacity'];
        if ($ll && $p['lat'] !== null) {
            $p['miles'] = round(miles_between($ll[0], $ll[1], (float)$p['lat'], (float)$p['lng']), 1);
            $p['rank'] = $p['miles'];
        } else {
            // No coordinates: fall back to ZIP closeness (same ZIP, then same 3-digit area).
            $p['miles'] = null;
            $uz = (string)$u['zip'];
            $p['rank'] = $uz === '' ? 9999 : ($p['zip'] === $uz ? 0.5 : (substr($p['zip'], 0, 3) === substr($uz, 0, 3) ? 5 + abs((int)$p['zip'] - (int)$uz) / 10 : 9999));
        }
    }
    unset($p);
    $points = array_values(array_filter($points, function ($p) { return $p['rank'] <= PICKUP_SEARCH_MILES; }));
    usort($points, function ($a, $b) { return $a['rank'] <=> $b['rank']; });
    return array_slice($points, 0, $limit);
}

function point_address(array $p): string
{
    return $p['address'] . ', ' . $p['city'] . ', ' . $p['state'] . ' ' . $p['zip'];
}

/** Points this member runs as a partner. */
function partner_points(int $userId, bool $activeOnly = false): array
{
    return q('SELECT * FROM pickup_points WHERE partner_user_id = ?' . ($activeOnly ? " AND status IN ('active','paused')" : '') . ' ORDER BY id', [$userId])->fetchAll();
}

function pudo_event(int $pointId, ?int $orderId, string $type, ?int $byUser = null, string $note = ''): void
{
    q('INSERT INTO pudo_events (point_id, order_id, type, user_id, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$pointId, $orderId, $type, $byUser, mb_substr($note, 0, 255)]);
}

// ---------- Order steps ----------
/** The shop has the parcel: give the buyer a pick-up code and a deadline. Idempotent. */
function order_mark_at_point(int $id, ?int $byUser = null): bool
{
    $o = order_get($id);
    if (!$o || $o['delivery_type'] !== 'pickup' || !in_array($o['status'], ['label_sent', 'shipped'], true)) return false;
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $deadline = date('Y-m-d H:i:s', time() + pickup_hold_days() * 86400);
    q("UPDATE orders SET status = 'at_pickup', pickup_code = ?, at_point_at = NOW(), pickup_deadline = ?, shipped_at = COALESCE(shipped_at, NOW()), updated_at = NOW() WHERE id = ?", [$code, $deadline, $id]);
    pudo_event((int)$o['pickup_point_id'], $id, 'received', $byUser);
    $p = q('SELECT * FROM pickup_points WHERE id = ?', [$o['pickup_point_id']])->fetch();
    notify((int)$o['buyer_id'], 'Your parcel is ready for pick-up at ' . $p['name'] . ', ' . point_address($p) . '. Your pick-up code is ' . $code
        . '. Bring a photo ID and collect it by ' . date('D, M j', strtotime($deadline)) . '.', 'order.php?id=' . $id);
    notify((int)$o['seller_id'], 'Order #' . $id . ' arrived at the pick-up point. You\'ll be paid after the buyer collects it and confirms.', 'order.php?id=' . $id, false);
    return true;
}

/** Buyer collected the parcel at the shop (code checked). Starts the Buyer Protection window. */
function order_mark_collected(int $id, ?int $byUser = null): void
{
    $o = order_get($id);
    if (!$o || $o['status'] !== 'at_pickup') return;
    q("UPDATE orders SET status = 'delivered', collected_at = NOW(), delivered_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
    pudo_event((int)$o['pickup_point_id'], $id, 'handover', $byUser);
    notify((int)$o['buyer_id'], 'You collected order #' . $id . '. Check your item and tap "Everything is OK", or report a problem within ' . PAYMENT_HOLD_HOURS . ' hours.', 'order.php?id=' . $id);
    notify((int)$o['seller_id'], 'The buyer collected order #' . $id . '. You\'ll be paid once they confirm, or automatically after ' . PAYMENT_HOLD_HOURS . ' hours.', 'order.php?id=' . $id, false);
}

/** Not collected in time: refund the buyer and send the parcel back to the seller. */
function order_return_uncollected(int $id): void
{
    $o = order_get($id);
    if (!$o || $o['status'] !== 'at_pickup') return;
    $seller = q('SELECT * FROM users WHERE id = ?', [$o['seller_id']])->fetch();
    $point = q('SELECT * FROM pickup_points WHERE id = ?', [$o['pickup_point_id']])->fetch();
    $label = null;
    if (shippo_enabled()) {
        try { $label = shippo_buy_return_label($o, $seller, $point); }
        catch (ShippoError $e) { error_log('Return label for order ' . $id . ': ' . $e->getMessage()); }
    }
    q('UPDATE orders SET return_due = 1, return_label_url = ?, return_tracking = ? WHERE id = ?',
      [$label['label_url'] ?? null, $label['tracking'] ?? ('RTN' . random_int(100000000, 999999999)), $id]);
    order_refund($id, false, 'system', 'The parcel wasn\'t collected within ' . pickup_hold_days() . ' days, so it is being returned to the seller.');
    pudo_event((int)$o['pickup_point_id'], $id, 'return_due');
    notify((int)$o['seller_id'], 'Order #' . $id . ' wasn\'t collected, so it is on its way back to you. Show the listing again once you have the item.', 'order.php?id=' . $id);
}

/** Return label from the pick-up point back to the seller (Shippo). */
function shippo_buy_return_label(array $o, array $seller, array $point): array
{
    $fake = $o;
    $fake['ship_name'] = $seller['full_name'];
    $fake['ship_address'] = $seller['address_line'];
    $fake['ship_city'] = $seller['city'];
    $fake['ship_state'] = $seller['state'];
    $fake['ship_zip'] = $seller['zip'];
    $fake['contact_phone'] = $seller['phone'];
    $fake['carrier'] = 'usps';
    $from = ['full_name' => $point['name'], 'address_line' => $point['address'], 'city' => $point['city'], 'state' => $point['state'],
             'zip' => $point['zip'], 'phone' => $point['contact_phone'], 'email' => $point['contact_email'] ?: SUPPORT_EMAIL];
    return shippo_buy_label($fake, $from, null);
}

/** Seller handed the parcel in at a partner shop. */
function order_mark_dropped_off(int $id, int $pointId, ?int $byUser = null): void
{
    $o = order_get($id);
    if (!$o || $o['status'] !== 'label_sent') return;
    q('UPDATE orders SET dropoff_point_id = ?, dropped_off_at = NOW() WHERE id = ?', [$pointId, $id]);
    pudo_event($pointId, $id, 'dropoff', $byUser);
    order_mark_shipped($id);
}
