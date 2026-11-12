<?php
require __DIR__ . '/inc/app.php';
if (is_post()) {
    check_csrf();
    $_SESSION = [];
    session_destroy();
}
redirect('index.php');
