<?php
// Served as /robots.txt (see .htaccess). Private and transactional pages are kept out of search engines.
require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$base = (string)parse_url(APP_URL, PHP_URL_PATH);
$private = ['admin/', 'checkout', 'order.php', 'orders.php', 'conversation.php', 'inbox.php', 'settings.php', 'security.php', 'profile.php',
            'wallet.php', 'payouts.php', 'favorites.php', 'saved_searches.php', 'following.php', 'blocked.php', 'report.php', 'sell.php',
            'label.php', 'partner.php', 'login.php', 'register.php', 'forgot.php', 'reset.php', 'verify.php', 'two_factor.php', 'install.php', 'cron.php'];
echo "User-agent: *\n";
foreach ($private as $p) echo 'Disallow: ' . $base . '/' . $p . "\n";
echo 'Disallow: ' . $base . "/catalog.php?*q=\n";   // search results: endless near-duplicates
echo "\nSitemap: " . APP_URL . "/sitemap.xml\n";
