<?php
// One-time tokens, login rate limiting and TOTP two-step verification.

// ---------- One-time tokens (email verification, password reset) ----------
function create_token(int $userId, string $type, int $minutes): string
{
    q('UPDATE tokens SET used_at = NOW() WHERE user_id = ? AND type = ? AND used_at IS NULL', [$userId, $type]);
    $raw = bin2hex(random_bytes(32));
    q('INSERT INTO tokens (user_id, type, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE, NOW())',
      [$userId, $type, hash('sha256', $raw), $minutes]);
    return $raw;
}

/** Returns the user id if valid. Marks the token used when $consume is true. */
function check_token(string $raw, string $type, bool $consume): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;
    $t = q('SELECT * FROM tokens WHERE token_hash = ? AND type = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $raw), $type])->fetch();
    if (!$t) return null;
    if ($consume) q('UPDATE tokens SET used_at = NOW() WHERE id = ?', [$t['id']]);
    return (int)$t['user_id'];
}

function send_verification_email(array $u): void
{
    $raw = create_token((int)$u['id'], 'verify', 60 * 48);
    queue_email($u['email'], 'Confirm your email for ' . APP_NAME, mail_layout('Welcome, ' . $u['username'] . '!', [
        'Please confirm this is your email address. You need a confirmed email to buy, sell and send messages.',
    ], ['Confirm email', app_url('verify.php?token=' . $raw)], 'This link expires in 48 hours. If you didn\'t sign up, you can ignore this email.'), (int)$u['id'], true);
}

function send_password_reset_email(array $u): void
{
    $raw = create_token((int)$u['id'], 'reset', 60);
    queue_email($u['email'], 'Reset your ' . APP_NAME . ' password', mail_layout('Hi ' . $u['username'] . ',', [
        'Someone (hopefully you) asked to reset your password. Tap the button to choose a new one.',
    ], ['Choose a new password', app_url('reset.php?token=' . $raw)], 'This link expires in 1 hour and can only be used once. If you didn\'t ask for this, ignore this email; your password won\'t change.'), (int)$u['id'], true);
}

// ---------- Rate limiting ----------
/** True when this IP, or this IP+identifier, has too many recent failures for $action. */
function rate_limited(string $action, string $identifier = '', int $max = 0, int $minutes = 0): bool
{
    $max = $max ?: LOGIN_MAX_ATTEMPTS;
    $minutes = $minutes ?: LOGIN_LOCK_MINUTES;
    $ip = client_ip();
    $byIp = (int)q('SELECT COUNT(*) FROM login_attempts WHERE action = ? AND ip = ? AND success = 0 AND created_at > NOW() - INTERVAL ? MINUTE', [$action, $ip, $minutes])->fetchColumn();
    if ($byIp >= $max * 4) return true;   // one IP hammering many accounts
    if ($identifier === '') return $byIp >= $max;
    $byId = (int)q('SELECT COUNT(*) FROM login_attempts WHERE action = ? AND identifier = ? AND success = 0 AND created_at > NOW() - INTERVAL ? MINUTE', [$action, mb_strtolower($identifier), $minutes])->fetchColumn();
    return $byId >= $max;
}

function record_attempt(string $action, string $identifier, bool $success): void
{
    q('INSERT INTO login_attempts (action, ip, identifier, success, created_at) VALUES (?, ?, ?, ?, NOW())', [$action, client_ip(), mb_strtolower(mb_substr($identifier, 0, 190)), $success ? 1 : 0]);
    if ($success && $identifier !== '') q('DELETE FROM login_attempts WHERE action = ? AND identifier = ? AND success = 0', [$action, mb_strtolower($identifier)]);
}

// ---------- TOTP (RFC 6238), works with Google Authenticator, 1Password, Authy… ----------
function base32_encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $b32): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_code(string $secret, int $counter): string
{
    $hash = hash_hmac('sha1', pack('N*', 0, $counter), base32_decode($secret), true);
    $o = ord($hash[19]) & 0xf;
    $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Verifies a 6-digit code (±30s drift) and blocks reuse of the same code. Returns the matched counter or null. */
function totp_verify(string $secret, string $code, int $lastCounter = 0): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $c = $now + $i;
        if ($c > $lastCounter && hash_equals(totp_code($secret, $c), $code)) return $c;
    }
    return null;
}

function totp_uri(string $secret, string $account): string
{
    return 'otpauth://totp/' . rawurlencode(APP_NAME . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode(APP_NAME) . '&digits=6&period=30';
}

/** Finishes a successful sign-in (after password, and after 2FA if enabled). */
function complete_login(array $row): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa_uid']);
    $_SESSION['uid'] = (int)$row['id'];
    $_SESSION['2fa_ok'] = !empty($row['totp_enabled']);
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
}
