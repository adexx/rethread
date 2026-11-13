<?php
// Saves or removes this device's push subscription (called from assets/app.js).
require __DIR__ . '/inc/app.php';
header('Content-Type: application/json');
$u = user();
if (!$u || !is_post() || !push_enabled()) { http_response_code(403); exit('{"ok":false}'); }
check_csrf();
$endpoint = (string)($_POST['endpoint'] ?? '');
try {
    if (($_POST['action'] ?? '') === 'remove') {
        push_unsubscribe((int)$u['id'], $endpoint);
    } else {
        push_subscribe((int)$u['id'], $endpoint, (string)($_POST['p256dh'] ?? ''), (string)($_POST['auth'] ?? ''));
        queue_push((int)$u['id'], APP_NAME, 'Push notifications are on for this device.', 'settings.php');
    }
    echo '{"ok":true}';
} catch (RuntimeException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
