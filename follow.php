<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
if (!is_post()) redirect('index.php');
check_csrf();
$id = (int)($_POST['id'] ?? 0);
$m = q('SELECT id, username FROM users WHERE id = ? AND deleted_at IS NULL AND banned = 0', [$id])->fetch();
if (!$m) redirect('index.php');
if (($_POST['action'] ?? '') === 'unfollow') {
    unfollow_user((int)$u['id'], $id);
    flash('You unfollowed ' . $m['username'] . '.');
} elseif (follow_user((int)$u['id'], $id)) {
    flash("You're following " . $m['username'] . ". Their new listings will show on your home page.");
} else {
    flash("You can't follow this member.", 'err');
}
redirect('member.php?id=' . $id);
