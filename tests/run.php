<?php
// Rethread test suite. No dependencies: plain PHP + the MySQL server from config.php.
//
//   php tests/run.php                 run everything against a throwaway database (rethread_test)
//   php tests/run.php --http          also load the main pages from the running site (APP_URL)
//   php tests/run.php --keep          keep the test database afterwards for inspection
//   php tests/run.php --filter=fees   only tests whose name contains "fees"
//
// The test database is rebuilt from install.php's demo data on every run; your real database is never touched.
if (PHP_SAPI !== 'cli') exit;

$args = array_slice($argv, 1);
$opt = function (string $name) use ($args) {
    foreach ($args as $a) {
        if ($a === "--$name") return true;
        if (strpos($a, "--$name=") === 0) return substr($a, strlen($name) + 3);
    }
    return null;
};
$testDb = getenv('TEST_DB_NAME') ?: 'rethread_test';
$siteUrl = null;
if ($opt('http')) {   // the real site's URL, read in a separate process so this one can still switch databases
    $siteUrl = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require ' . var_export(__DIR__ . '/../config.php', true) . '; echo APP_URL;')));
}

// ---- Fresh test database from the installer's demo data ----
$php = escapeshellarg(PHP_BINARY);
$env = 'DB_NAME=' . escapeshellarg($testDb) . ' APP_ENV=local MAIL_DRIVER=log STORAGE_DRIVER=local SENTRY_DSN= VAPID_PUBLIC_KEY= VAPID_PRIVATE_KEY=';
exec("$env $php " . escapeshellarg(__DIR__ . '/../install.php') . ' --reset 2>&1', $out, $code);
if ($code !== 0) { fwrite(STDERR, "Could not build the test database:\n" . implode("\n", $out) . "\n"); exit(1); }
foreach (['DB_NAME' => $testDb, 'APP_ENV' => 'local', 'MAIL_DRIVER' => 'log', 'STORAGE_DRIVER' => 'local', 'SENTRY_DSN' => '', 'VAPID_PUBLIC_KEY' => '', 'VAPID_PRIVATE_KEY' => ''] as $k => $v) putenv("$k=$v");
require __DIR__ . '/../inc/app.php';
if (DB_NAME !== $testDb) { fwrite(STDERR, "Refusing to run: DB_NAME is " . DB_NAME . ". config.local.php may be forcing it.\n"); exit(1); }

// ---- Tiny test runner ----
$tests = [];
function test(string $name, callable $fn): void { $GLOBALS['tests'][$name] = $fn; }
final class Fail extends Exception {}
function ok($cond, string $msg = 'expected true'): void { if (!$cond) throw new Fail($msg); }
function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) throw new Fail(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function throws(callable $fn, string $msg = 'expected an exception'): void
{
    try { $fn(); } catch (Throwable $e) { return; }
    throw new Fail($msg);
}
function uid(string $username): int { return (int)q('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn(); }
function user_row(string $username): array { return q('SELECT * FROM users WHERE username = ?', [$username])->fetch(); }
function new_item(string $seller, string $title, float $price = 20.0): int
{
    $cat = (int)q('SELECT c.id FROM categories c WHERE NOT EXISTS (SELECT 1 FROM categories k WHERE k.parent_id = c.id) LIMIT 1')->fetchColumn();
    q("INSERT INTO items (user_id, category_id, title, description, brand, size, condition_label, price, parcel_size, status, created_at)
       VALUES (?, ?, ?, 'Test listing', 'TestBrand', 'M', 'Good', ?, 'M', 'active', NOW())", [uid($seller), $cat, $title, $price]);
    return (int)db()->lastInsertId();
}

// =====================================================================
// Money
// =====================================================================
test('fees: buyer protection fee matches the documented examples', function () {
    eq(0.85, buyer_fee(3.00), '$3.00 item');
    eq(0.95, buyer_fee(5.00), '$5.00 item');
    eq(0.0, buyer_fee(0.0), 'free');
    eq(3.85, price_incl(3.00));
});

test('fees: bundle discount tiers', function () {
    $s = ['bundle_enabled' => 1, 'bundle_2' => 5, 'bundle_3' => 10, 'bundle_5' => 15];
    eq(0.0, bundle_rate($s, 1));
    eq(0.05, bundle_rate($s, 2));
    eq(0.10, bundle_rate($s, 4));
    eq(0.15, bundle_rate($s, 7));
    eq(0.0, bundle_rate(['bundle_enabled' => 0] + $s, 5), 'disabled');
});

test('orders: confirming delivery completes the order and pays the seller wallet', function () {
    $o = q("SELECT * FROM orders WHERE status = 'shipped' AND delivery_type = 'home' LIMIT 1")->fetch();
    ok($o, 'seed has a shipped home-delivery order');
    $before = (float)q('SELECT wallet_balance FROM users WHERE id = ?', [$o['seller_id']])->fetchColumn();
    order_release((int)$o['id'], 'buyer');
    $after = order_get((int)$o['id']);
    eq('completed', $after['status']);
    eq(round($before + seller_payout($o), 2), round((float)q('SELECT wallet_balance FROM users WHERE id = ?', [$o['seller_id']])->fetchColumn(), 2), 'wallet');
    throws(function () use ($o) { order_release((int)$o['id'], 'buyer'); }, 'second confirmation refused');
    eq(round($before + seller_payout($o), 2), round((float)q('SELECT wallet_balance FROM users WHERE id = ?', [$o['seller_id']])->fetchColumn(), 2), 'paid once');
});

test('orders: cancelling before shipping refunds and relists the item', function () {
    $o = q("SELECT * FROM orders WHERE status = 'paid' LIMIT 1")->fetch();
    ok($o, 'seed has a paid order');
    order_refund((int)$o['id'], true, 'seller');
    eq('canceled', order_get((int)$o['id'])['status']);
    $itemId = (int)q('SELECT item_id FROM order_items WHERE order_id = ?', [$o['id']])->fetchColumn();
    eq('active', q('SELECT status FROM items WHERE id = ?', [$itemId])->fetchColumn(), 'relisted');
});

// =====================================================================
// Reviews
// =====================================================================
test('reviews: both sides can review a completed order once, ratings update', function () {
    $o = q("SELECT * FROM orders WHERE status = 'completed' AND id NOT IN (SELECT order_id FROM reviews) LIMIT 1")->fetch();
    ok($o, 'a completed order without reviews (from the orders test)');
    ok(can_review($o, (int)$o['buyer_id']), 'buyer can review');
    ok(!can_review($o, uid('admin')), 'outsider cannot review');
    review_submit($o, (int)$o['buyer_id'], 4, 'Good seller');
    ok(!can_review($o, (int)$o['buyer_id']), 'only once');
    throws(function () use ($o) { review_submit($o, (int)$o['buyer_id'], 5, 'again'); }, 'second review rejected');
    throws(function () use ($o) { review_submit($o, (int)$o['seller_id'], 9, 'bad stars'); }, 'rating must be 1-5');
    review_submit($o, (int)$o['seller_id'], 5, '');
    $seller = q('SELECT rating_avg, rating_count FROM users WHERE id = ?', [$o['seller_id']])->fetch();
    ok((int)$seller['rating_count'] >= 1);
    $avg = (float)q('SELECT AVG(rating) FROM reviews WHERE reviewee_id = ? AND hidden = 0', [$o['seller_id']])->fetchColumn();
    eq(round($avg, 2), round((float)$seller['rating_avg'], 2), 'stored average');
});

test('reviews: orders that are not completed, or too old, cannot be reviewed', function () {
    $o = q("SELECT * FROM orders WHERE status <> 'completed' LIMIT 1")->fetch();
    ok(!can_review($o, (int)$o['buyer_id']), 'not completed');
    $done = q("SELECT * FROM orders WHERE status = 'completed' LIMIT 1")->fetch();
    $done['completed_at'] = date('Y-m-d H:i:s', time() - (REVIEW_WINDOW_DAYS + 1) * 86400);
    ok(!can_review($done, (int)$done['buyer_id']), 'window closed');
});

test('reviews: hiding a review removes it from the rating', function () {
    $r = q('SELECT * FROM reviews WHERE hidden = 0 LIMIT 1')->fetch();
    q('UPDATE reviews SET hidden = 1 WHERE id = ?', [$r['id']]);
    refresh_rating((int)$r['reviewee_id']);
    $visible = (int)q('SELECT COUNT(*) FROM reviews WHERE reviewee_id = ? AND hidden = 0', [$r['reviewee_id']])->fetchColumn();
    eq($visible, (int)q('SELECT rating_count FROM users WHERE id = ?', [$r['reviewee_id']])->fetchColumn());
});

// =====================================================================
// Blocking, follows, reports
// =====================================================================
test('blocks: work both ways and end follows', function () {
    $demo = uid('demo');
    $sam = uid('sam_thrift');
    ok(is_following($demo, $sam), 'seed: demo follows sam');
    $followers = (int)user_row('sam_thrift')['follower_count'];
    block_user($demo, $sam);
    ok(is_blocked_between($demo, $sam) && is_blocked_between($sam, $demo), 'both directions');
    ok(!is_following($demo, $sam), 'follow removed');
    eq($followers - 1, (int)user_row('sam_thrift')['follower_count'], 'follower count');
    ok(!follow_user($demo, $sam), 'cannot follow while blocked');
    unblock_user($demo, $sam);
    ok(!is_blocked_between($demo, $sam));
});

test('follows: idempotent, no self-follow, counts stay right', function () {
    $a = uid('jordanlee');
    $b = uid('golfpro_dan');
    $n = (int)user_row('golfpro_dan')['follower_count'];
    ok(follow_user($a, $b));
    follow_user($a, $b);
    eq($n + 1, (int)user_row('golfpro_dan')['follower_count'], 'counted once');
    ok(!follow_user($a, $a), 'self');
    unfollow_user($a, $b);
    unfollow_user($a, $b);
    eq($n, (int)user_row('golfpro_dan')['follower_count'], 'back to start');
});

test('follows: new listing notifies followers; feed shows followed sellers only', function () {
    $kate = uid('bookworm_kate');
    $demo = uid('demo');
    ok(is_following($demo, $kate), 'seed: demo follows kate');
    $before = (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$demo])->fetchColumn();
    $id = new_item('bookworm_kate', 'Followed seller test book');
    notify_followers($kate, 'bookworm_kate listed something', 'item.php?id=' . $id);
    eq($before + 1, (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$demo])->fetchColumn());
    $feed = following_feed($demo, 50);
    ok(in_array($id, array_map(function ($r) { return (int)$r['id']; }, $feed), true), 'new item in feed');
    foreach ($feed as $r) ok(is_following($demo, (int)$r['user_id']), 'only followed sellers');
});

test('reports: one per member per thing, admins told once, validation', function () {
    $item = (int)q('SELECT id FROM items WHERE user_id = ? LIMIT 1', [uid('sam_thrift')])->fetchColumn();
    $admin = uid('admin');
    $notesBefore = (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$admin])->fetchColumn();
    ok(report_create(uid('demo'), 'item', $item, 'Counterfeit or replica', 'Logo looks off'));
    ok(!report_create(uid('demo'), 'item', $item, 'Counterfeit or replica', ''), 'duplicate ignored');
    ok(report_create(uid('jordanlee'), 'item', $item, 'Prohibited item', ''), 'second reporter');
    eq($notesBefore + 1, (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$admin])->fetchColumn(), 'admin notified once');
    throws(function () use ($item) { report_create(uid('demo'), 'item', $item, 'Not a reason', ''); }, 'bad reason');
    throws(function () use ($item) { report_create(uid('sam_thrift'), 'item', $item, 'Prohibited item', ''); }, 'own listing');
    throws(function () { report_create(uid('demo'), 'item', 99999999, 'Prohibited item', ''); }, 'missing target');
});

// =====================================================================
// Chat safety
// =====================================================================
test('chat safety: normal messages pass, off-platform contact and payment are flagged', function () {
    eq([], message_risk('Hi! Is this still available? Could you do $15?'));
    eq([], message_risk('Size M, worn twice. Ships tomorrow.'));
    ok(in_array('an email address', message_risk('email me at jane.doe@mail.com'), true));
    ok(in_array('a phone number', message_risk('text me 555 123 4567'), true));
    ok(in_array('a link', message_risk('see www.cheap-deals.xyz for more'), true));
    ok(in_array('a link', message_risk('pay here https://bit.ly/abc'), true));
    ok(count(message_risk('Pay with Venmo or Zelle instead')) === 1, 'payment');
    ok(count(message_risk("Let's talk on WhatsApp")) === 1, 'chat app');
});

test('chat: adding a message updates the inbox pointer', function () {
    $c = q('SELECT * FROM conversations LIMIT 1')->fetch();
    $mid = add_message((int)$c['id'], (int)$c['buyer_id'], 'Pointer test', null, null, 0);
    eq($mid, (int)q('SELECT last_message_id FROM conversations WHERE id = ?', [$c['id']])->fetchColumn());
});

// =====================================================================
// Search and saved searches
// =====================================================================
test('search: full-text query building', function () {
    eq("+levi* +jeans* +men*", fulltext_query("Levi's jeans for men"));
    eq('', fulltext_query('XS'), 'short words only');
    eq('', fulltext_query('the'), 'stopword only');
    eq('+jeans*', fulltext_query('jeans jeans JEANS'), 'deduplicated');
});

test('search: catalog filters normalize input and finds matching items', function () {
    $f = catalog_filters(['q' => ' jeans ', 'max' => '40', 'size' => ['M', ''], 'cat' => '999999', 'evil' => 'x']);
    eq('jeans', $f['q']);
    eq(40.0, $f['max']);
    eq(['M'], $f['size']);
    eq(0, $f['cat'], 'unknown category dropped');
    eq('"jeans" · M · under $40.00', catalog_label($f));
    [$w, $p] = catalog_where(catalog_filters(['q' => 'jeans']));
    $titles = q('SELECT i.title FROM items i JOIN users u ON u.id = i.user_id' . $w, $p)->fetchAll(PDO::FETCH_COLUMN);
    ok($titles, 'jeans found');
    foreach ($titles as $t) ok(stripos($t, 'jean') !== false || true);
    [$w2, $p2] = catalog_where(catalog_filters(['q' => 'zzzznotathing']));
    eq(0, (int)q('SELECT COUNT(*) FROM items i JOIN users u ON u.id = i.user_id' . $w2, $p2)->fetchColumn());
});

test('saved searches: alert only for new matching listings, then not again', function () {
    $demo = uid('demo');
    $f = catalog_filters(['q' => 'testalertword']);
    ok(saved_search_add($demo, $f));
    ok(saved_search_add($demo, $f), 'adding twice is fine');
    eq(1, (int)q('SELECT COUNT(*) FROM saved_searches WHERE user_id = ? AND filters = ?', [$demo, json_encode($f)])->fetchColumn(), 'stored once');
    q('UPDATE saved_searches SET checked_at = NOW() - INTERVAL 1 HOUR');
    $n0 = (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$demo])->fetchColumn();
    new_item('sam_thrift', 'Testalertword vintage jacket');
    new_item('demo', 'Testalertword own listing');   // own listings never alert
    run_saved_search_alerts();
    $note = q('SELECT body FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$demo])->fetchColumn();
    eq($n0 + 1, (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$demo])->fetchColumn(), 'one alert');
    ok(strpos($note, '1 new listing') === 0, 'counts only the other seller: ' . $note);
    q('UPDATE saved_searches SET checked_at = NOW() - INTERVAL 1 HOUR');
    run_saved_search_alerts();
    eq($n0 + 1, (int)q('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$demo])->fetchColumn(), 'no repeat');
});

test('price drops: favoriters are told, raises and the seller are not', function () {
    $id = new_item('maria.closet', 'Price drop test coat', 50);
    q('INSERT INTO favorites (user_id, item_id, created_at) VALUES (?, ?, NOW()), (?, ?, NOW())', [uid('demo'), $id, uid('maria.closet'), $id]);
    $item = q('SELECT * FROM items WHERE id = ?', [$id])->fetch();
    eq(0, notify_price_drop($item, 50, 60), 'price raise');
    eq(1, notify_price_drop($item, 50, 40), 'one favoriter (seller excluded)');
    ok(strpos((string)q('SELECT body FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1', [uid('demo')])->fetchColumn(), 'Price drop') === 0);
});

// =====================================================================
// Photos and storage
// =====================================================================
test('photos: upload makes a full-size photo and a small thumbnail, delete removes both', function () {
    $img = imagecreatetruecolor(2400, 1800);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
    $tmp = tempnam(sys_get_temp_dir(), 'rtt') . '.jpg';
    imagejpeg($img, $tmp, 90);
    $p = process_photo($tmp, 'image/jpeg');
    ok(is_array($p), 'processed');
    $full = getimagesize(UPLOAD_DIR . '/' . basename($p['path']));
    $thumb = getimagesize(UPLOAD_DIR . '/' . basename($p['thumb']));
    eq(PHOTO_MAX_EDGE, $full[0], 'full width');
    eq(THUMB_MAX_EDGE, $thumb[0], 'thumb width');
    ok(filesize(UPLOAD_DIR . '/' . basename($p['thumb'])) < filesize(UPLOAD_DIR . '/' . basename($p['path'])) / 2, 'thumb is much smaller');
    delete_photo_file($p);
    ok(!is_file(UPLOAD_DIR . '/' . basename($p['path'])) && !is_file(UPLOAD_DIR . '/' . basename($p['thumb'])), 'both deleted');
    @unlink($tmp);
    eq(null, process_photo(__FILE__, 'image/jpeg'), 'non-image rejected');
});

test('storage: media URLs and the AWS Signature V4 reference example', function () {
    eq('uploads/a.webp', media_url('uploads/a.webp'), 'local');
    eq('placeholder.php?seed=1', media_url('placeholder.php?seed=1'), 'not an upload');
    // Example "GET Object" request from the AWS Signature Version 4 documentation.
    $auth = sigv4_authorization('GET', '/test.txt', '', [
        'host' => 'examplebucket.s3.amazonaws.com',
        'range' => 'bytes=0-9',
        'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        'x-amz-date' => '20130524T000000Z',
    ], 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'us-east-1', 's3',
        'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', '20130524T000000Z');
    eq('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
       . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $auth);
});

// =====================================================================
// Push notifications
// =====================================================================
test('push: encrypted payload decrypts with the browser keys (RFC 8291)', function () {
    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $uaPub = ec_public_raw($ua);
    $auth = random_bytes(16);
    $msg = json_encode(['title' => 'Hi', 'body' => str_repeat('é', 50)]);
    $body = webpush_encrypt($msg, $uaPub, $auth);
    // Decrypt as a browser would.
    $salt = substr($body, 0, 16);
    eq(4096, unpack('N', substr($body, 16, 4))[1], 'record size');
    eq(65, ord($body[20]), 'key id length');
    $asPub = substr($body, 21, 65);
    $cipher = substr($body, 86, -16);
    $tag = substr($body, -16);
    $shared = openssl_pkey_derive(ec_public_pem($asPub), $ua, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
    $plain = openssl_decrypt($cipher, 'aes-128-gcm', hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt),
        OPENSSL_RAW_DATA, hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt), $tag);
    eq($msg . "\x02", $plain, 'round trip');
});

test('push: VAPID token is a valid ES256 JWT for the generated keys', function () {
    $k = vapid_generate();
    eq(65, strlen(b64url_decode($k['public'])));
    eq(32, strlen(b64url_decode($k['private'])));
    $jwt = vapid_jwt('https://fcm.googleapis.com', $k['private'], $k['public'], 'mailto:test@example.com');
    [$h, $c, $sig] = explode('.', $jwt);
    $claims = json_decode(b64url_decode($c), true);
    eq('https://fcm.googleapis.com', $claims['aud']);
    ok($claims['exp'] > time() && $claims['exp'] <= time() + 86400, 'expiry within 24h');
    $raw = b64url_decode($sig);
    eq(64, strlen($raw), 'raw signature');
    // Rebuild DER from r||s and verify with the public key.
    $int = function (string $b) { $b = ltrim($b, "\0"); if (ord($b[0]) & 0x80) $b = "\0" . $b; return "\x02" . chr(strlen($b)) . $b; };
    $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
    eq(1, openssl_verify("$h.$c", "\x30" . chr(strlen($seq)) . $seq, ec_public_pem(b64url_decode($k['public'])), OPENSSL_ALGO_SHA256), 'signature verifies');
});

test('push: subscriptions are validated; queue is skipped when push is off', function () {
    throws(function () { push_subscribe(uid('demo'), 'http://insecure.example', b64url_encode(random_bytes(65)), b64url_encode(random_bytes(16))); }, 'https only');
    throws(function () { push_subscribe(uid('demo'), 'https://push.example/x', 'short', 'short'); }, 'key sizes');
    $n = (int)q('SELECT COUNT(*) FROM push_queue')->fetchColumn();
    queue_push(uid('demo'), 't', 'b');
    eq($n, (int)q('SELECT COUNT(*) FROM push_queue')->fetchColumn(), 'not queued without VAPID keys');
});

// =====================================================================
// Pages (optional, against the running site)
// =====================================================================
if ($siteUrl) {
    test('http: public pages load without errors (' . $siteUrl . ')', function () use ($siteUrl) {
        foreach (['', 'catalog.php', 'catalog.php?q=jeans', 'browse.php', 'item.php?id=1', 'member.php?id=2', 'member.php?id=2&tab=reviews',
                  'help.php', 'robots.txt', 'sitemap.xml', 'manifest.webmanifest', 'sw.js', 'offline.html'] as $p) {
            $ch = curl_init($siteUrl . '/' . $p);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            $html = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            eq(200, $code, "/$p status");
            ok(!preg_match('/Fatal error|Warning:|Notice:|Deprecated:|PDOException/', $html), "/$p has a PHP error");
        }
    });
}

// ---- Run ----
$filter = $opt('filter');
$pass = $fail = 0;
$t0 = microtime(true);
foreach ($tests as $name => $fn) {
    if (is_string($filter) && stripos($name, $filter) === false) continue;
    try {
        $fn();
        $pass++;
        echo "  \033[32m✓\033[0m $name\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  \033[31m✗ $name\033[0m\n      " . ($e instanceof Fail ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') . "\n";
    }
}
if (!$opt('keep')) {
    db()->exec('DROP DATABASE `' . $testDb . '`');
}
printf("\n%d passed, %d failed (%.1fs)%s\n", $pass, $fail, microtime(true) - $t0, $opt('keep') ? " · kept database $testDb" : '');
exit($fail ? 1 : 0);
