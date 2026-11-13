<?php
// Error monitoring: sends PHP exceptions, fatal errors and browser errors to Sentry when SENTRY_DSN is set.
// Without a DSN everything still goes to the PHP error log as before.

/** Parses https://<key>@<host>/<project> into the envelope URL and auth header, or null when unset/invalid. */
function sentry_target(): ?array
{
    static $t = false;
    if ($t !== false) return $t;
    $t = null;
    if (SENTRY_DSN === '') return null;
    $p = parse_url(SENTRY_DSN);
    if (empty($p['user']) || empty($p['host']) || empty($p['path'])) return null;
    $project = trim($p['path'], '/');
    $base = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    // Self-hosted Sentry under a sub-path: https://key@host/sub/path/42
    $prefix = '';
    if (strpos($project, '/') !== false) { $prefix = '/' . substr($project, 0, strrpos($project, '/')); $project = substr($project, strrpos($project, '/') + 1); }
    return $t = [
        'url' => $base . $prefix . '/api/' . $project . '/envelope/',
        'auth' => 'Sentry sentry_version=7, sentry_client=rethread-php/1.0, sentry_key=' . $p['user'],
    ];
}

/** Sends one event to Sentry. Never throws: monitoring must not break the page it is reporting on. */
function sentry_send(array $event): bool
{
    $t = sentry_target();
    if (!$t) return false;
    static $sent = 0;
    if (++$sent > 5) return false;                     // a broken loop shouldn't send hundreds of events per request
    $event += [
        'event_id' => bin2hex(random_bytes(16)),
        'timestamp' => microtime(true),
        'platform' => 'php',
        'level' => 'error',
        'environment' => APP_ENV,
        'server_name' => gethostname() ?: 'server',
    ];
    if (PHP_SAPI !== 'cli') {
        $event['request'] = $event['request'] ?? ['url' => (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?'), 'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'];
        if (!empty($_SESSION['uid'])) $event['user'] = ['id' => (string)$_SESSION['uid']];   // id only, no email or name
    }
    $body = json_encode(['event_id' => $event['event_id'], 'sent_at' => gmdate('Y-m-d\TH:i:s\Z')]) . "\n"
          . json_encode(['type' => 'event']) . "\n" . json_encode($event, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    try {
        $ch = curl_init($t['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-sentry-envelope', 'X-Sentry-Auth: ' . $t['auth']],
        ]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
        curl_close($ch);
        return $ok;
    } catch (Throwable $e) {
        return false;
    }
}

function monitor_capture(Throwable $e, array $tags = []): void
{
    error_log((string)$e);
    $frames = [];
    foreach (array_reverse($e->getTrace()) as $f) {
        $frames[] = ['filename' => isset($f['file']) ? str_replace(dirname(__DIR__) . '/', '', $f['file']) : '[internal]', 'lineno' => $f['line'] ?? 0,
                     'function' => (isset($f['class']) ? $f['class'] . ($f['type'] ?? '::') : '') . ($f['function'] ?? '')];
    }
    $frames[] = ['filename' => str_replace(dirname(__DIR__) . '/', '', $e->getFile()), 'lineno' => $e->getLine(), 'function' => '(thrown)'];
    sentry_send([
        'exception' => ['values' => [['type' => get_class($e), 'value' => mb_substr($e->getMessage(), 0, 1000), 'stacktrace' => ['frames' => $frames]]]],
        'tags' => $tags,
    ]);
}

function monitor_message(string $message, string $level = 'error', array $extra = []): void
{
    sentry_send(['message' => ['formatted' => mb_substr($message, 0, 2000)], 'level' => $level, 'extra' => $extra]);
}

/** Catches uncaught exceptions and fatal errors. Shows a friendly page in production. */
function monitor_init(): void
{
    set_exception_handler(function (Throwable $e) {
        monitor_capture($e);
        if (!headers_sent()) http_response_code(500);
        if (IS_PRODUCTION || PHP_SAPI === 'cli') {
            if (PHP_SAPI === 'cli') { fwrite(STDERR, (string)$e . PHP_EOL); return; }
            echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;padding:24px">'
               . '<h2>Something went wrong</h2><p>Please try again in a moment. If it keeps happening, contact ' . htmlspecialchars(SUPPORT_EMAIL) . '.</p></body>';
        } else {
            echo '<pre style="white-space:pre-wrap;padding:16px">' . htmlspecialchars((string)$e) . '</pre>';
        }
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            monitor_message($err['message'] . ' in ' . str_replace(dirname(__DIR__) . '/', '', $err['file']) . ':' . $err['line'], 'fatal');
        }
    });
}
