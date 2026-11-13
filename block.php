<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
if (!is_post()) redirect('blocked.php');
check_csrf();
$id = (int)($_POST['id'] ?? 0);
$m = q('SELECT id, username FROM users WHERE id = ?', [$id])->fetch();
if (!$m || $id === (int)$u['id']) redirect('index.php');
if (($_POST['action'] ?? '') === 'unblock') {
    unblock_user((int)$u['id'], $id);
    flash('You unblocked ' . $m['username'] . '.');
} else {
    block_user((int)$u['id'], $id);
    flash('You blocked ' . $m['username'] . ". They can't message you or buy your items. If they did something wrong, please also report them.");
}
$next = (string)($_POST['next'] ?? '');
redirect($next !== '' ? safe_next($next) : 'member.php?id=' . $id);
