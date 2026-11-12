<?php
// Stripe Connect (Express accounts) using "separate charges and transfers":
//   1. Buyer pays the platform through Stripe Checkout.
//   2. The money stays on the platform until the buyer confirms (or the hold period passes).
//   3. The seller's share is transferred to their connected account; Stripe pays it to their bank.
// No SDK needed: plain HTTPS calls with curl.

final class StripeError extends RuntimeException {}

function stripe_enabled(): bool
{
    return STRIPE_SECRET_KEY !== '';
}

function stripe_is_test(): bool
{
    return strpos(STRIPE_SECRET_KEY, 'sk_test_') === 0 || strpos(STRIPE_SECRET_KEY, 'rk_test_') === 0;
}

function cents(float $amount): int
{
    return (int)round($amount * 100);
}

function stripe_request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
{
    if (!stripe_enabled()) throw new StripeError('Stripe is not configured.');
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $body = http_build_query($params, '', '&');
    if ($method === 'GET' && $body !== '') { $url .= '?' . $body; $body = ''; }
    $headers = ['Stripe-Version: 2024-06-20'];
    if ($idempotencyKey) $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => STRIPE_SECRET_KEY . ':',
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new StripeError('Could not reach Stripe: ' . $err);
    $data = json_decode((string)$raw, true);
    if ($code >= 400 || !is_array($data)) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        error_log("Stripe $method $path failed: $msg");
        throw new StripeError($msg);
    }
    return $data;
}

/** Verifies the Stripe-Signature header. Returns the decoded event or null. */
function stripe_verify_webhook(string $payload, string $header, string $secret, int $tolerance = 300): ?array
{
    if ($secret === '' || $header === '') return null;
    $t = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $t = (int)$v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if (!$t || !$sigs || abs(time() - $t) > $tolerance) return null;
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) {
            $event = json_decode($payload, true);
            return is_array($event) ? $event : null;
        }
    }
    return null;
}

/** Creates (once) and returns the seller's Express account id. */
function stripe_ensure_account(array $u): string
{
    if (!empty($u['stripe_account_id'])) return $u['stripe_account_id'];
    $acct = stripe_request('POST', 'accounts', [
        'type' => 'express',
        'country' => 'US',
        'email' => $u['email'],
        'business_type' => 'individual',
        'capabilities' => ['transfers' => ['requested' => 'true']],
        'business_profile' => ['product_description' => 'Selling second-hand items on ' . APP_NAME, 'mcc' => '5931'],
        'metadata' => ['user_id' => (string)$u['id']],
    ], 'acct_user_' . $u['id']);
    q('UPDATE users SET stripe_account_id = ? WHERE id = ?', [$acct['id'], $u['id']]);
    return $acct['id'];
}

function stripe_sync_account(int $userId, array $acct): void
{
    $ready = !empty($acct['details_submitted']) && !empty($acct['payouts_enabled'])
          && (($acct['capabilities']['transfers'] ?? '') === 'active');
    q('UPDATE users SET payouts_enabled = ? WHERE id = ?', [$ready ? 1 : 0, $userId]);
}
