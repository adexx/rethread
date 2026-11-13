<?php
// Router for PHP's built-in web server, so the app runs without Apache:
//   php -S localhost:8000 scripts/dev-router.php
// It mirrors the rules in .htaccess. For development and CI only; use Apache or nginx in production.
$root = dirname(__DIR__);
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Never serve internals, config, docs or scripts in uploads.
if (preg_match('#(^|/)(inc|scripts|backups|tests|docker|\.git|\.github)(/|$)#', $path)
    || preg_match('#/(config(\.local)?\.php(\.example)?|[^/]+\.(md|ya?ml)|Dockerfile|\.env.*|\.git.*)$#i', $path)
    || preg_match('#^/uploads/.*\.(php\d?|phtml|phar)$#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

$generated = ['/robots.txt' => 'robots.php', '/sitemap.xml' => 'sitemap.php'];
if (isset($generated[$path])) {
    $_SERVER['SCRIPT_NAME'] = '/' . $generated[$path];
    chdir($root);
    require $root . '/' . $generated[$path];
    return true;
}
if (str_ends_with($path, '.webmanifest') && is_file($root . $path)) {
    header('Content-Type: application/manifest+json');
    readfile($root . $path);
    return true;
}
if ($path === '/sw.js') header('Cache-Control: no-cache');

return false; // let the built-in server handle PHP files and static assets
