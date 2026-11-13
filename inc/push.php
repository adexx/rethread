<?php
// Web Push notifications (RFC 8030 / 8291 / 8292) using only PHP's OpenSSL and cURL.
// Messages are queued in push_queue and sent by cron.php, so a slow push service never slows a page.

function push_enabled(): bool
{
    return VAPID_PUBLIC_KEY !== '' && VAPID_PRIVATE_KEY !== '';
}

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** Queues a push for a member, only if push is set up and they have turned it on on a device. */
function queue_push(int $userId, string $title, string $body, string $url = ''): void
{
    if (!push_enabled()) return;
    if (!q('SELECT 1 FROM push_subscriptions WHERE user_id = ? LIMIT 1', [$userId])->fetchColumn()) return;
    q('INSERT INTO push_queue (user_id, title, body, url, created_at) VALUES (?, ?, ?, ?, NOW())',
      [$userId, mb_substr($title, 0, 120), mb_substr($body, 0, 300), mb_substr($url, 0, 500)]);
}

function push_subscribe(int $userId, string $endpoint, string $p256dh, string $auth): void
{
    if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 1000) throw new RuntimeException('Invalid push endpoint.');
    if (strlen(b64url_decode($p256dh)) !== 65 || strlen(b64url_decode($auth)) !== 16) throw new RuntimeException('Invalid push keys.');
    q('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?, NOW())
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)',
      [$userId, $endpoint, hash('sha256', $endpoint), $p256dh, $auth]);
}

function push_unsubscribe(int $userId, string $endpoint): void
{
    q('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
}

/** Cron job: sends queued pushes. Returns [sent, failed]. */
function send_push_queue(int $limit = 200): array
{
    if (!push_enabled()) { q('DELETE FROM push_queue'); return [0, 0]; }
    q('DELETE FROM push_queue WHERE created_at < NOW() - INTERVAL 1 DAY');   // stale news isn't worth a buzz
    $rows = q('SELECT * FROM push_queue ORDER BY id LIMIT ' . (int)$limit)->fetchAll();
    $sent = $failed = 0;
    foreach ($rows as $r) {
        q('DELETE FROM push_queue WHERE id = ?', [$r['id']]);
        $payload = json_encode(['title' => $r['title'], 'body' => $r['body'], 'url' => $r['url'] !== '' ? app_url($r['url']) : app_url('inbox.php')]);
        foreach (q('SELECT * FROM push_subscriptions WHERE user_id = ?', [$r['user_id']])->fetchAll() as $s) {
            try {
                $code = webpush_send($s['endpoint'], $s['p256dh'], $s['auth'], $payload);
                if ($code === 404 || $code === 410) q('DELETE FROM push_subscriptions WHERE id = ?', [$s['id']]);   // device unsubscribed
                if ($code >= 200 && $code < 300) $sent++; else $failed++;
            } catch (Throwable $e) {
                $failed++;
                error_log('push: ' . $e->getMessage());
            }
        }
    }
    return [$sent, $failed];
}

// ---------- Protocol ----------

/** Sends one encrypted push message. Returns the push service's HTTP status. */
function webpush_send(string $endpoint, string $p256dh, string $auth, string $payload, int $ttl = 86400): int
{
    $body = webpush_encrypt($payload, b64url_decode($p256dh), b64url_decode($auth));
    $p = parse_url($endpoint);
    $jwt = vapid_jwt($p['scheme'] . '://' . $p['host'], VAPID_PRIVATE_KEY, VAPID_PUBLIC_KEY, 'mailto:' . SUPPORT_EMAIL);
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY,
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: ' . $ttl,
            'Urgency: normal',
        ],
    ]);
    $res = curl_exec($ch);
    if ($res === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException('Push request failed: ' . $err); }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/**
 * Encrypts a payload for one browser subscription (RFC 8291, aes128gcm).
 * $uaPublic: the browser's 65-byte P-256 key (p256dh); $authSecret: its 16-byte auth secret.
 * $asKey/$salt are only passed by tests; normally they are fresh for every message.
 */
function webpush_encrypt(string $payload, string $uaPublic, string $authSecret, $asKey = null, ?string $salt = null): string
{
    $asKey = $asKey ?: openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPublic = ec_public_raw($asKey);
    $shared = openssl_pkey_derive(ec_public_pem($uaPublic), $asKey, 32);
    if ($shared === false) throw new RuntimeException('Push key agreement failed.');
    $salt = $salt ?? random_bytes(16);
    // RFC 8291 §3.4: combine the shared secret with the browser's auth secret, then derive the content key and nonce.
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);   // \x02 = last (only) record
    if ($cipher === false) throw new RuntimeException('Push encryption failed.');
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** VAPID (RFC 8292) token that proves this server owns the public key the browser subscribed with. */
function vapid_jwt(string $audience, string $privateB64, string $publicB64, string $subject): string
{
    $head = b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64url_encode(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
    $key = ec_private_pem(b64url_decode($privateB64), b64url_decode($publicB64));
    if (!openssl_sign($head . '.' . $claims, $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('VAPID signing failed.');
    return $head . '.' . $claims . '.' . b64url_encode(ecdsa_der_to_raw($der));
}

/** Creates a new VAPID key pair as base64url strings. */
function vapid_generate(): array
{
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($k)['ec']['d'];
    return ['public' => b64url_encode(ec_public_raw($k)), 'private' => b64url_encode(str_pad($d, 32, "\0", STR_PAD_LEFT))];
}

// ---------- P-256 key format helpers ----------

function ec_public_raw($key): string
{
    $ec = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
}

/** Wraps a raw 65-byte public key in a PEM SubjectPublicKeyInfo. */
function ec_public_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Wraps a raw 32-byte private scalar (+ its public key) in a PEM SEC1 EC private key. */
function ec_private_pem(string $d, string $pub)
{
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub;
    $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    $key = openssl_pkey_get_private($pem);
    if (!$key) throw new RuntimeException('Invalid VAPID private key.');
    return $key;
}

/** OpenSSL returns ECDSA signatures as DER; JWT (ES256) wants r||s, 32 bytes each. */
function ecdsa_der_to_raw(string $der): string
{
    $pos = 2;                                          // skip SEQUENCE header (always short form for P-256)
    $out = '';
    for ($n = 0; $n < 2; $n++) {
        $len = ord($der[$pos + 1]);                    // INTEGER length
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}
