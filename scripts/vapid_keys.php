<?php
// Generates the key pair for push notifications. Run once, then put the two lines in
// config.local.php (or your server's environment). Changing keys later signs everyone out of push.
//   php scripts/vapid_keys.php
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/push.php';
$k = vapid_generate();
echo "define('LOCAL_VAPID_PUBLIC_KEY', '{$k['public']}');\n";
echo "define('LOCAL_VAPID_PRIVATE_KEY', '{$k['private']}');\n";
echo "\nKeep the private key secret. Environment variables work too: VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY.\n";
