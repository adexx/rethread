<?php
// Shippo tracking updates. In Shippo > Settings > API > Webhooks, add:
//   Event: track_updated    URL: https://YOUR-DOMAIN/shippo_webhook.php?token=YOUR_SHIPPO_WEBHOOK_TOKEN
require __DIR__ . '/inc/app.php';

if (SHIPPO_WEBHOOK_TOKEN === '' || !hash_equals(SHIPPO_WEBHOOK_TOKEN, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body) || ($body['event'] ?? '') !== 'track_updated') { http_response_code(200); exit('Ignored'); }

$data = $body['data'] ?? [];
$tracking = (string)($data['tracking_number'] ?? '');
$status = strtoupper((string)($data['tracking_status']['status'] ?? ''));
if ($tracking === '' || $status === '') { http_response_code(200); exit('Ignored'); }

$o = q("SELECT * FROM orders WHERE tracking = ? AND status IN ('label_sent','shipped','delivered')", [$tracking])->fetch();
if (!$o) { http_response_code(200); exit('No matching order'); }

switch ($status) {
    case 'TRANSIT':
        order_mark_shipped((int)$o['id']);
        break;
    case 'DELIVERED':
        if ($o['status'] === 'label_sent') order_mark_shipped((int)$o['id']);
        order_mark_delivered((int)$o['id']);
        break;
    case 'RETURNED':
    case 'FAILURE':
        $detail = (string)($data['tracking_status']['status_details'] ?? '');
        order_suspend((int)$o['id'], 'the carrier reported a delivery problem' . ($detail !== '' ? ' (' . mb_substr($detail, 0, 150) . ')' : '') . '.');
        foreach (q("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $a) notify((int)$a, 'Delivery problem on order #' . $o['id'] . '.', 'admin/order.php?id=' . $o['id']);
        break;
}
http_response_code(200);
echo 'ok';
