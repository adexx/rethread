<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/icons.php';

// ---------- Environment & HTTP hardening ----------
date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', IS_PRODUCTION ? '0' : '1');
ini_set('log_errors', '1');
require_once __DIR__ . '/monitor.php';
monitor_init();   // uncaught errors: logged, sent to Sentry when configured, friendly page in production

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

if (PHP_SAPI !== 'cli') {
    if (FORCE_HTTPS && !is_https()) {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        session_name('rt_session');
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => is_https()]);
    }
}

// ---------- Database ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Keep MySQL's NOW() in the same timezone as PHP's date()/time().
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<body style="font-family:system-ui;background:#16191c;color:#eee;padding:24px">'
               . '<h2>Database not ready</h2><p>' . e($e->getMessage()) . '</p>'
               . '<p>Start MySQL in MAMP, check <code>config.php</code>, then run <a style="color:#f2a65a" href="install.php">install.php</a>.</p></body>';
            exit;
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// ---------- Output helpers ----------
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money($n): string
{
    return '$' . number_format((float)$n, 2);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function time_ago(string $ts): string
{
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) { $m = intdiv($d, 60); return $m . ' minute' . ($m > 1 ? 's' : '') . ' ago'; }
    if ($d < 86400) { $h = intdiv($d, 3600); return $h . ' hour' . ($h > 1 ? 's' : '') . ' ago'; }
    if ($d < 86400 * 30) { $x = intdiv($d, 86400); return $x . ' day' . ($x > 1 ? 's' : '') . ' ago'; }
    return date('M j, Y', strtotime($ts));
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid form token. Go back, refresh the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

// ---------- Auth ----------
function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
            // Banned or deleted accounts are signed out on their next request.
            if ($u && (!empty($u['banned']) || !empty($u['deleted_at']))) { unset($_SESSION['uid']); $u = null; }
        }
    }
    return $u;
}

function require_login(): array
{
    $u = user();
    if (!$u) redirect('login.php?next=' . urlencode(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php') . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING'])));
    return $u;
}

/** Buying, selling and messaging need a confirmed email address. */
function require_verified(): array
{
    $u = require_login();
    if (empty($u['email_verified_at'])) {
        flash('Please confirm your email address first. We sent you a link; you can resend it below.', 'err');
        redirect('verify.php');
    }
    return $u;
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function is_admin(): bool
{
    $u = user();
    return $u !== null && ($u['role'] ?? 'user') === 'admin';
}

function safe_next(string $next): string
{
    // Only allow relative in-app paths.
    if ($next === '' || preg_match('#^(https?:)?//#i', $next) || strpos($next, "\n") !== false) return 'index.php';
    return $next;
}

// ---------- Settings (admin-editable, fall back to config.php) ----------
function setting(string $key, $default = null)
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) $all[$r['k']] = $r['v'];
        } catch (PDOException $e) { /* table not installed yet */ }
    }
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function fee_fixed(): float { return (float)setting('fee_fixed', FEE_FIXED); }
function fee_rate(): float { return (float)setting('fee_rate', FEE_RATE); }
function tax_rate(): float { return (float)setting('tax_rate', SALES_TAX_RATE); }

// ---------- Money logic ----------
function buyer_fee(float $subtotal): float
{
    if ($subtotal <= 0) return 0.0;
    return round(fee_fixed() + $subtotal * fee_rate(), 2);
}

function price_incl(float $price): float
{
    return round($price + buyer_fee($price), 2);
}

function bundle_rate(array $seller, int $count): float
{
    if (empty($seller['bundle_enabled']) || $count < 2) return 0.0;
    if ($count >= 5) return (float)$seller['bundle_5'] / 100;
    if ($count >= 3) return (float)$seller['bundle_3'] / 100;
    return (float)$seller['bundle_2'] / 100;
}

const PARCEL_SIZES = [
    'S' => ['Small',  'For items that fit in a large envelope, e.g. a T-shirt or a book.'],
    'M' => ['Medium', 'For items that fit in a shoebox, e.g. a sweater or sneakers.'],
    'L' => ['Large',  'For items that fit in a moving box, e.g. a coat or boots.'],
];

const CARRIERS = [
    'pickup' => ['name' => 'SpeedLink to Drop Shop', 'logo' => 'SL', 'days' => 'At pick-up point in 3 - 7 business days',
                 'prices' => ['S' => 0.00, 'M' => 1.99, 'L' => 4.49]],
    'usps'   => ['name' => 'USPS Ground Advantage',  'logo' => 'US', 'days' => 'Home delivery, 2 - 5 business days',
                 'prices' => ['S' => 1.49, 'M' => 4.99, 'L' => 8.99]],
    'fedex'  => ['name' => 'FedEx Ground Economy',   'logo' => 'FX', 'days' => 'Home delivery, 3 - 7 business days',
                 'prices' => ['S' => 2.99, 'M' => 5.99, 'L' => 9.99]],
];

function largest_parcel(array $items): string
{
    $order = ['S' => 1, 'M' => 2, 'L' => 3];
    $best = 'S';
    foreach ($items as $it) {
        if (($order[$it['parcel_size']] ?? 1) > $order[$best]) $best = $it['parcel_size'];
    }
    return $best;
}

function shipping_cost(string $carrier, string $parcel): float
{
    return (float)setting("ship_{$carrier}_{$parcel}", CARRIERS[$carrier]['prices'][$parcel] ?? 0);
}

const ORDER_STATUS = [
    'pending_payment' => 'Waiting for payment…',
    'at_pickup' => 'Ready for pick-up!',
    'delivered' => 'Delivered! Check your item.',
    'paid'      => 'Payment successful!',
    'label_sent'=> 'Shipping label sent to seller.',
    'shipped'   => 'Order shipped and on its way!',
    'completed' => 'Order completed.',
    'canceled'  => 'Order canceled.',
    'suspended' => 'Order suspended.',
];

const CONDITIONS = [
    'New with tags'    => 'Brand-new, unused item with tags attached or in the original packaging.',
    'New without tags' => 'Brand-new, unused item without tags or original packaging.',
    'Very good'        => 'Lightly used item that may have slight imperfections, but still looks great.',
    'Good'             => 'Used item that may show imperfections and signs of wear.',
    'Satisfactory'     => 'Frequently used item with imperfections and signs of wear.',
];

const COLORS = [
    'Black' => '#111', 'White' => '#f5f5f5', 'Gray' => '#8a8f98', 'Beige' => '#d8c3a5', 'Brown' => '#7a5230',
    'Navy' => '#1f2f5c', 'Blue' => '#3b74d6', 'Green' => '#3f8f4f', 'Red' => '#c8352e', 'Pink' => '#e88fb0',
    'Purple' => '#7d4cc2', 'Yellow' => '#e8c33a', 'Orange' => '#e57f2e', 'Multi' => 'linear-gradient(135deg,#e57f2e,#3b74d6,#3f8f4f)',
];

const MATERIALS = ['Cotton', 'Denim', 'Wool', 'Cashmere', 'Polyester', 'Fleece', 'Linen', 'Leather', 'Silk', 'Nylon', 'Wood', 'Metal', 'Plastic', 'Paper', 'Other'];

// ---------- Categories ----------
function categories(): array
{
    static $cats = null;
    if ($cats === null) {
        $cats = [];
        foreach (q('SELECT * FROM categories ORDER BY sort, id')->fetchAll() as $c) $cats[(int)$c['id']] = $c;
    }
    return $cats;
}

function category_children(?int $parent): array
{
    return array_values(array_filter(categories(), function ($c) use ($parent) {
        return ($c['parent_id'] === null ? null : (int)$c['parent_id']) === $parent;
    }));
}

function category_descendants(int $id): array
{
    $ids = [$id];
    foreach (category_children($id) as $c) $ids = array_merge($ids, category_descendants((int)$c['id']));
    return $ids;
}

function category_path(int $id): array
{
    $path = [];
    $cats = categories();
    while ($id && isset($cats[$id])) {
        array_unshift($path, $cats[$id]);
        $id = (int)$cats[$id]['parent_id'];
    }
    return $path;
}

function category_path_label(int $id, string $sep = ' › '): string
{
    return implode($sep, array_map(function ($c) { return $c['name']; }, category_path($id)));
}

// ---------- Items ----------
function item_select(): string
{
    return 'SELECT i.*, u.username,
              (SELECT COALESCE(p.thumb, p.path) FROM item_photos p WHERE p.item_id = i.id ORDER BY p.sort, p.id LIMIT 1) AS photo,
              (SELECT COUNT(*) FROM favorites f WHERE f.item_id = i.id) AS favs
            FROM items i JOIN users u ON u.id = i.user_id';
}

// Loads full item rows (with cover photo and favorite count) for the given ids, keeping their order.
function items_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $rows = q(item_select() . ' WHERE i.id IN (' . implode(',', $ids) . ')')->fetchAll();
    $byId = [];
    foreach ($rows as $r) $byId[(int)$r['id']] = $r;
    $out = [];
    foreach ($ids as $id) if (isset($byId[$id])) $out[] = $byId[$id];
    return $out;
}

// Lists items in two steps: first pick one page of ids using only the indexes, then load the
// photo and favorite count for just those rows (not for every row that matched).
// $where and $order may use the aliases i (items) and u (users); $order must not come from user input.
// STRAIGHT_JOIN makes MySQL read items first, walking the sort index and stopping after one page;
// otherwise it tends to start from users and sort every visible listing.
function item_list(string $where, array $params, string $order, int $limit, int $offset = 0): array
{
    $ids = q('SELECT i.id FROM items i STRAIGHT_JOIN users u ON u.id = i.user_id' . $where
        . ' ORDER BY ' . $order . ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset), $params)->fetchAll(PDO::FETCH_COLUMN);
    return items_by_ids($ids);
}

// Turns search-box text into a MySQL boolean full-text query: every word required, matched as a prefix
// ("jean" finds "jeans"). Words InnoDB doesn't index (under 3 letters, stopwords) are dropped.
// Returns '' when nothing indexable is left.
function fulltext_query(string $text): string
{
    static $stop = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it',
        'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = [];
    foreach ($words as $w) {
        if (mb_strlen($w) < 3 || in_array($w, $stop, true)) continue;
        $terms['+' . $w . '*'] = true;
    }
    return implode(' ', array_slice(array_keys($terms), 0, 8));
}

function like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

// Small read-through cache for data that may be a few minutes stale (filter options and the like).
// Uses APCu when the server has it, otherwise JSON files in the system temp folder.
function cache_remember(string $key, int $ttl, callable $fn)
{
    $key = 'rethread:' . md5(__DIR__ . '|' . DB_HOST . '|' . DB_NAME) . ':' . $key;
    $apcu = function_exists('apcu_enabled') && apcu_enabled();
    if ($apcu) {
        $hit = apcu_fetch($key, $ok);
        if ($ok) return $hit;
    } else {
        $file = sys_get_temp_dir() . '/rethread-cache-' . md5($key) . '.json';
        if (is_file($file) && filemtime($file) > time() - $ttl) {
            $hit = json_decode((string)@file_get_contents($file), true);
            if (is_array($hit) && array_key_exists('v', $hit)) return $hit['v'];
        }
    }
    $value = $fn();
    if ($apcu) {
        apcu_store($key, $value, $ttl);
    } else {
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['v' => $value])) !== false) @rename($tmp, $file);
    }
    return $value;
}

function user_favorites(): array
{
    $u = user();
    if (!$u) return [];
    $ids = q('SELECT item_id FROM favorites WHERE user_id = ?', [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
    return array_flip(array_map('intval', $ids));
}

function photo_url(?string $path, string $seed = 'x'): string
{
    if ($path) return media_url($path);
    return 'placeholder.php?seed=' . urlencode($seed);
}

/** In-app notification, plus an email when the member has email notifications on. */
function notify(int $userId, string $body, string $link, bool $email = true): void
{
    q('INSERT INTO notifications (user_id, body, link, created_at) VALUES (?, ?, ?, NOW())', [$userId, $body, $link]);
    queue_push($userId, APP_NAME, $body, $link);
    if ($email) {
        $u = q('SELECT email, username, email_notifications, deleted_at FROM users WHERE id = ?', [$userId])->fetch();
        if ($u && $u['email_notifications'] && !$u['deleted_at']) {
            $subject = mb_strlen($body) > 70 ? mb_substr($body, 0, 67) . '…' : $body;
            queue_email($u['email'], $subject, mail_layout('Hi ' . $u['username'] . ',', [$body], $link ? ['View on ' . APP_NAME, app_url($link)] : null), $userId);
        }
    }
}

function app_url(string $path = ''): string
{
    return APP_URL . '/' . ltrim($path, '/');
}

function unread_counts(): array
{
    $u = user();
    if (!$u) return ['messages' => 0, 'notifications' => 0];
    // Runs on every page (tab bar dot), so each side of the conversation list uses its own index.
    $m = (int)q('SELECT COUNT(*) FROM (SELECT id FROM conversations WHERE buyer_id = ? UNION ALL SELECT id FROM conversations WHERE seller_id = ?) c
                 JOIN messages m ON m.conversation_id = c.id AND m.read_at IS NULL AND m.sender_id <> ?',
                 [$u['id'], $u['id'], $u['id']])->fetchColumn();
    $n = (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']])->fetchColumn();
    return ['messages' => $m, 'notifications' => $n];
}

/** Price this buyer pays for an item: an accepted offer overrides the list price. */
function effective_price(array $item, ?int $buyerId): float
{
    if ($buyerId) {
        $offer = q("SELECT m.offer_amount FROM messages m JOIN conversations c ON c.id = m.conversation_id
                    WHERE c.item_id = ? AND c.buyer_id = ? AND m.offer_status = 'accepted'
                    ORDER BY m.id DESC LIMIT 1", [$item['id'], $buyerId])->fetchColumn();
        if ($offer !== false) return (float)$offer;
    }
    return (float)$item['price'];
}

function avatar(array $u, int $size = 40): string
{
    $letter = strtoupper(substr((string)$u['username'], 0, 1));
    $color = $u['avatar_color'] ?? '#5b7c99';
    return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * .42) . 'px;background:' . e($color) . '">' . e($letter) . '</span>';
}

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/shippo.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/pudo.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/push.php';
require_once __DIR__ . '/community.php';
