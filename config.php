<?php
// ---------------------------------------------------------------
// Rethread configuration.
// Every value can be set by an environment variable of the same
// name (recommended on a live server, so secrets stay out of code),
// or by creating config.local.php, which is loaded first and is
// never committed. See DEPLOY.md.
// ---------------------------------------------------------------
if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

function cfg(string $name, $default)
{
    if (defined('LOCAL_' . $name)) return constant('LOCAL_' . $name);
    $v = getenv($name);
    if ($v === false || $v === '') return $default;
    if (is_bool($default)) return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    if (is_float($default)) return (float)$v;
    if (is_int($default)) return (int)$v;
    return $v;
}

// ---- App ----
define('APP_NAME', cfg('APP_NAME', 'Rethread'));
define('APP_ENV', cfg('APP_ENV', 'local'));              // 'local' or 'production'
define('APP_URL', rtrim(cfg('APP_URL', 'http://localhost/vinted'), '/')); // used in emails & redirects from Stripe
define('IS_PRODUCTION', APP_ENV === 'production');
define('APP_TIMEZONE', cfg('APP_TIMEZONE', 'America/Los_Angeles'));   // PHP and MySQL both use this
define('FORCE_HTTPS', cfg('FORCE_HTTPS', IS_PRODUCTION));

// ---- Company / legal (shown on Terms, Privacy, emails) ----
define('COMPANY_NAME', cfg('COMPANY_NAME', 'Rethread LLC'));
define('COMPANY_ADDRESS', cfg('COMPANY_ADDRESS', '123 Example St, Portland, OR 97201'));
define('SUPPORT_EMAIL', cfg('SUPPORT_EMAIL', 'support@example.com'));
define('GOVERNING_STATE', cfg('GOVERNING_STATE', 'Oregon'));
// Set to true only after a lawyer has reviewed the legal pages; until then they show a "draft" notice.
define('LEGAL_REVIEWED', cfg('LEGAL_REVIEWED', false));

// ---- Database ----
define('DB_HOST', cfg('DB_HOST', '127.0.0.1'));
define('DB_PORT', cfg('DB_PORT', '3306'));
define('DB_NAME', cfg('DB_NAME', 'rethread'));
define('DB_USER', cfg('DB_USER', 'root'));
define('DB_PASS', cfg('DB_PASS', 'root'));

// ---- Fees (defaults; admins can override in Admin > Fees & shipping) ----
define('FEE_FIXED', cfg('FEE_FIXED', 0.70));
define('FEE_RATE', cfg('FEE_RATE', 0.05));
define('SALES_TAX_RATE', cfg('SALES_TAX_RATE', 0.0));

// ---- Payments: Stripe Connect ----
// Leave STRIPE_SECRET_KEY empty to run in demo mode (no real payments).
// Use sk_test_... keys until you're ready to go live.
define('STRIPE_SECRET_KEY', cfg('STRIPE_SECRET_KEY', ''));
define('STRIPE_WEBHOOK_SECRET', cfg('STRIPE_WEBHOOK_SECRET', ''));
define('STRIPE_AUTOMATIC_TAX', cfg('STRIPE_AUTOMATIC_TAX', false)); // needs Stripe Tax set up in your dashboard
define('PAYMENT_HOLD_HOURS', cfg('PAYMENT_HOLD_HOURS', 48));         // auto-complete this long after delivery

// ---- Shipping: Shippo ----
// Leave empty to generate demo labels. Use shippo_test_... keys first.
define('SHIPPO_API_KEY', cfg('SHIPPO_API_KEY', ''));
define('SHIPPO_WEBHOOK_TOKEN', cfg('SHIPPO_WEBHOOK_TOKEN', ''));     // random string; add ?token=... to the webhook URL

// ---- Email ----
// 'log' = store emails in the database only (visible in Admin > Emails). 'smtp' = send for real.
define('MAIL_DRIVER', cfg('MAIL_DRIVER', 'log'));
define('MAIL_FROM', cfg('MAIL_FROM', 'no-reply@example.com'));
define('MAIL_FROM_NAME', cfg('MAIL_FROM_NAME', APP_NAME));
define('SMTP_HOST', cfg('SMTP_HOST', ''));
define('SMTP_PORT', cfg('SMTP_PORT', 587));
define('SMTP_USER', cfg('SMTP_USER', ''));
define('SMTP_PASS', cfg('SMTP_PASS', ''));
define('SMTP_SECURE', cfg('SMTP_SECURE', 'tls'));   // 'tls' (STARTTLS, port 587) or 'ssl' (port 465)

// ---- Security ----
define('ADMIN_2FA_REQUIRED', cfg('ADMIN_2FA_REQUIRED', true));
define('CRON_TOKEN', cfg('CRON_TOKEN', ''));          // lets cron.php run over HTTP: cron.php?token=...
define('LOGIN_MAX_ATTEMPTS', cfg('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCK_MINUTES', cfg('LOGIN_LOCK_MINUTES', 15));

// ---- Uploads ----
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_BYTES', 12 * 1024 * 1024);
define('MAX_PHOTOS', 8);
define('PHOTO_MAX_EDGE', 1600);
define('THUMB_MAX_EDGE', cfg('THUMB_MAX_EDGE', 480));   // small copy used in grids, chats and lists

// ---- Photo storage ----
// 'local' keeps photos in uploads/. 's3' stores them in an S3-compatible bucket
// (AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2...). See DEPLOY.md.
define('STORAGE_DRIVER', cfg('STORAGE_DRIVER', 'local'));
define('S3_BUCKET', cfg('S3_BUCKET', ''));
define('S3_REGION', cfg('S3_REGION', 'us-east-1'));      // 'auto' for Cloudflare R2
define('S3_KEY', cfg('S3_KEY', ''));
define('S3_SECRET', cfg('S3_SECRET', ''));
define('S3_ENDPOINT', cfg('S3_ENDPOINT', ''));          // empty for AWS; e.g. https://<account>.r2.cloudflarestorage.com
// Public base URL photos are served from, e.g. your CDN: https://cdn.example.com
// Empty = served by this site (local) or straight from the bucket (s3).
define('MEDIA_URL', rtrim(cfg('MEDIA_URL', ''), '/'));

// ---- Push notifications (Web Push) ----
// Generate a key pair once with: php scripts/vapid_keys.php
define('VAPID_PUBLIC_KEY', cfg('VAPID_PUBLIC_KEY', ''));
define('VAPID_PRIVATE_KEY', cfg('VAPID_PRIVATE_KEY', ''));

// ---- Error monitoring ----
// Paste a Sentry DSN (https://<key>@<host>/<project>) to receive PHP and browser errors.
define('SENTRY_DSN', cfg('SENTRY_DSN', ''));
