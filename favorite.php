<?php
require __DIR__ . '/inc/app.php';
$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$u = user();
if (!$u) {
    if ($ajax) { header('Content-Type: application/json'); echo json_encode(['login' => 'login.php']); exit; }
    redirect('login.php');
}
if (!is_post()) redirect('index.php');
check_csrf();
$id = (int)($_POST['id'] ?? 0);
$exists = q('SELECT 1 FROM favorites WHERE user_id = ? AND item_id = ?', [$u['id'], $id])->fetchColumn();
if ($exists) {
    q('DELETE FROM favorites WHERE user_id = ? AND item_id = ?', [$u['id'], $id]);
} elseif (q('SELECT 1 FROM items WHERE id = ?', [$id])->fetchColumn()) {
    q('INSERT INTO favorites (user_id, item_id, created_at) VALUES (?, ?, NOW())', [$u['id'], $id]);
    $owner = (int)q('SELECT user_id FROM items WHERE id = ?', [$id])->fetchColumn();
    if ($owner !== (int)$u['id']) notify($owner, $u['username'] . ' favorited your item.', 'item.php?id=' . $id);
}
$count = (int)q('SELECT COUNT(*) FROM favorites WHERE item_id = ?', [$id])->fetchColumn();
if ($ajax) {
    header('Content-Type: application/json');
    echo json_encode(['favorited' => !$exists, 'count' => $count]);
    exit;
}
redirect('item.php?id=' . $id);
