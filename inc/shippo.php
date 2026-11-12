<?php
// Shippo: buys real USPS / FedEx labels and sends tracking updates to shippo_webhook.php.

final class ShippoError extends RuntimeException {}

// Typical box size and weight per parcel size (inches / pounds). Adjust to your catalog.
const PARCEL_DIMENSIONS = [
    'S' => ['length' => '10', 'width' => '7',  'height' => '2', 'weight' => '1'],
    'M' => ['length' => '14', 'width' => '10', 'height' => '5', 'weight' => '3'],
    'L' => ['length' => '18', 'width' => '14', 'height' => '8', 'weight' => '6'],
];

function shippo_enabled(): bool
{
    return SHIPPO_API_KEY !== '';
}

function shippo_request(string $method, string $path, ?array $body = null): array
{
    if (!shippo_enabled()) throw new ShippoError('Shippo is not configured.');
    $ch = curl_init('https://api.goshippo.com/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: ShippoToken ' . SHIPPO_API_KEY, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new ShippoError('Could not reach Shippo: ' . $err);
    $data = json_decode((string)$raw, true);
    if ($code >= 400 || !is_array($data)) {
        $msg = is_array($data) ? json_encode($data) : ('HTTP ' . $code);
        error_log("Shippo $method $path failed: $msg");
        throw new ShippoError('Shipping provider error: ' . mb_substr($msg, 0, 200));
    }
    return $data;
}

/**
 * Buys a label for an order. Returns ['tracking', 'label_url', 'tracking_url', 'transaction_id', 'cost'].
 * The parcel goes to the buyer's address, or to the chosen pick-up point.
 */
function shippo_buy_label(array $o, array $seller, ?array $point): array
{
    foreach (['full_name', 'address_line', 'city', 'state', 'zip'] as $k) {
        if (trim((string)$seller[$k]) === '') throw new ShippoError('Add your full address in Settings before getting a label.');
    }
    $from = [
        'name' => $seller['full_name'], 'street1' => $seller['address_line'], 'city' => $seller['city'],
        'state' => $seller['state'], 'zip' => $seller['zip'], 'country' => 'US',
        'phone' => $seller['phone'], 'email' => $seller['email'],
    ];
    if ($point) {
        $to = ['name' => $o['ship_name'], 'company' => $point['name'], 'street1' => $point['address'], 'city' => $point['city'],
               'state' => $point['state'] ?: $o['ship_state'], 'zip' => $point['zip'], 'country' => 'US', 'phone' => $o['contact_phone']];
    } else {
        $to = ['name' => $o['ship_name'], 'street1' => $o['ship_address'], 'city' => $o['ship_city'], 'state' => $o['ship_state'],
               'zip' => $o['ship_zip'], 'country' => 'US', 'phone' => $o['contact_phone']];
    }
    $parcel = PARCEL_DIMENSIONS[$o['parcel_size']] + ['distance_unit' => 'in', 'mass_unit' => 'lb'];

    $shipment = shippo_request('POST', 'shipments/', [
        'address_from' => $from, 'address_to' => $to, 'parcels' => [$parcel], 'async' => false,
        'metadata' => 'Order ' . $o['id'],
    ]);
    $rates = $shipment['rates'] ?? [];
    if (!$rates) {
        $msgs = array_map(function ($m) { return $m['text'] ?? ''; }, $shipment['messages'] ?? []);
        throw new ShippoError('No shipping rates for these addresses. ' . implode(' ', array_filter($msgs)));
    }
    $want = ['usps' => ['USPS', 'ground_advantage'], 'fedex' => ['FedEx', 'ground_economy'], 'pickup' => ['', '']][$o['carrier']] ?? ['', ''];
    $pick = function (callable $f) use ($rates) {
        $m = array_values(array_filter($rates, $f));
        usort($m, function ($a, $b) { return (float)$a['amount'] <=> (float)$b['amount']; });
        return $m[0] ?? null;
    };
    $rate = $pick(function ($r) use ($want) { return $want[0] && strcasecmp($r['provider'], $want[0]) === 0 && strpos($r['servicelevel']['token'] ?? '', $want[1]) !== false; })
         ?? $pick(function ($r) use ($want) { return $want[0] && strcasecmp($r['provider'], $want[0]) === 0; })
         ?? $pick(function ($r) { return true; });

    $tx = shippo_request('POST', 'transactions/', ['rate' => $rate['object_id'], 'label_file_type' => 'PDF_4x6', 'async' => false, 'metadata' => 'Order ' . $o['id']]);
    if (($tx['status'] ?? '') !== 'SUCCESS') {
        $msgs = array_map(function ($m) { return $m['text'] ?? ''; }, $tx['messages'] ?? []);
        throw new ShippoError('Label purchase failed. ' . implode(' ', array_filter($msgs)));
    }
    return [
        'tracking' => $tx['tracking_number'], 'label_url' => $tx['label_url'], 'tracking_url' => $tx['tracking_url_provider'] ?? '',
        'transaction_id' => $tx['object_id'], 'cost' => (float)$rate['amount'], 'carrier_name' => $rate['provider'] . ' ' . ($rate['servicelevel']['name'] ?? ''),
    ];
}

/** Asks Shippo to refund an unused label (best effort). */
function shippo_refund_label(string $transactionId): void
{
    try { shippo_request('POST', 'refunds/', ['transaction' => $transactionId, 'async' => true]); }
    catch (ShippoError $e) { error_log('Label refund failed: ' . $e->getMessage()); }
}
