<?php
// Receives browser errors from the page (see inc/layout.php) and forwards them to Sentry.
require __DIR__ . '/inc/app.php';
http_response_code(204);
if (!is_post() || SENTRY_DSN === '') exit;
if (rate_limited('js_error', client_ip(), 30, 10)) exit;
record_attempt('js_error', client_ip(), false);
$d = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($d) || empty($d['message'])) exit;
$str = function ($k, $max) use ($d) { return mb_substr((string)($d[$k] ?? ''), 0, $max); };
sentry_send([
    'platform' => 'javascript',
    'logger' => 'browser',
    'message' => ['formatted' => $str('message', 500)],
    'request' => ['url' => app_url(ltrim($str('page', 300), '/')), 'headers' => ['User-Agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)]],
    'extra' => ['source' => $str('source', 300), 'line' => (int)($d['line'] ?? 0), 'col' => (int)($d['col'] ?? 0), 'stack' => $str('stack', 3000)],
    'tags' => ['side' => 'browser'],
]);
