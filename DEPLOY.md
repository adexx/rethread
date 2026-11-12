# Taking Rethread live

Follow these steps in order. After each one, check **Admin › Go-live checklist**. It reads your real configuration and shows what's still missing.

> Test everything with **test keys** (Stripe `sk_test_…`, Shippo `shippo_test_…`) on the live server before switching to live keys. Test mode moves no real money and buys no real labels.

---

## 1. Business & legal (outside the code)

- Form a company (an LLC, for example) and open a business bank account. Stripe pays your platform fees into it.
- Have a lawyer review and adapt `terms.php`, `privacy.php` and `prohibited.php` for your state and business model. Then set `LEGAL_REVIEWED=true`. Until you do, these pages show a "Draft" notice.
- **Sales tax:** most US states require marketplaces to collect and pay sales tax for their sellers once you pass certain sales thresholds. The simplest route is to enable **Stripe Tax** in the Stripe Dashboard and set `STRIPE_AUTOMATIC_TAX=true`. Ask an accountant when to register in each state.
- Pick a name and brand that are your own. Check the trademark before you launch.

## 2. Hosting

You need PHP 8.1+ (with `pdo_mysql`, `gd` (WebP), `exif`, `curl`, `openssl`, `fileinfo`, `mbstring`), MySQL 8 and Apache (or nginx with equivalent rules). Good options:

- A small VPS (DigitalOcean, Hetzner, Linode) with Ubuntu, Apache, PHP-FPM and MySQL, or
- Managed PHP hosting (Cloudways, Laravel Forge plus a VPS, etc.).

Then:

1. Point your domain at the server and enable HTTPS (free certificates from Let's Encrypt: `certbot --apache`).
2. Upload the files (everything except `config.local.php`, `backups/` and `uploads/*`).
3. Create a database and a dedicated user:
   ```sql
   CREATE DATABASE rethread CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'rethread'@'localhost' IDENTIFIED BY 'a-long-random-password';
   GRANT ALL ON rethread.* TO 'rethread'@'localhost';
   ```
4. Copy `config.local.php.example` to `config.local.php` and fill it in (or use environment variables with the same names). Set `APP_ENV=production` and an `https://` `APP_URL`.
5. Create the tables from the command line: `php install.php`. On an empty database this also adds demo data. Delete the demo accounts afterwards, or start from a clean dump of the schema. Then **delete `install.php`** from the server.
6. Make `uploads/` writable by the web server (`chown -R www-data uploads`).
7. Sign in as `admin@example.com`, set up two-step verification and change the password. Better still, register your own account, make it admin, and delete that one.

## 3. Payments: Stripe Connect

1. Create a Stripe account and complete the business verification.
2. **Connect › Get started:** choose *Marketplace* and **Express** accounts. Fill in your platform profile and branding.
3. **Developers › API keys:** copy the secret key into `STRIPE_SECRET_KEY`.
4. **Developers › Webhooks › Add endpoint:**
   - URL: `https://YOUR-DOMAIN/stripe_webhook.php`
   - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `charge.dispute.created`, `account.updated`
   - Also tick **Listen to events on Connected accounts**, or add a second endpoint for Connect events, so `account.updated` arrives.
   - Copy the signing secret (`whsec_…`) into `STRIPE_WEBHOOK_SECRET`.
5. Test the whole flow:
   1. A seller sets up payouts with Stripe's test identity values.
   2. A buyer pays with card `4242 4242 4242 4242`.
   3. The seller gets a label and marks the order shipped.
   4. The buyer confirms. Check that a Transfer appears in the Stripe Dashboard.
   5. Try a refund and a dispute (card `4000 0000 0000 0259` triggers one).

How the money moves: the buyer pays the platform; the money is held until the buyer confirms (or `PAYMENT_HOLD_HOURS` after delivery); then the seller's share is **transferred** to their Stripe account and Stripe pays it to their bank. The Buyer Protection fee and shipping stay with the platform. Shipping pays for the label.

## 4. Shipping: Shippo

1. Create a Shippo account and add a payment method; labels are billed to it.
2. Copy the API token into `SHIPPO_API_KEY`.
3. **Settings › API › Webhooks:** add `track_updated` → `https://YOUR-DOMAIN/shippo_webhook.php?token=YOUR_SHIPPO_WEBHOOK_TOKEN`. Set the same random string in `SHIPPO_WEBHOOK_TOKEN`.
4. Check the parcel sizes and weights in `inc/shippo.php` (`PARCEL_DIMENSIONS`) and the shipping prices in **Admin › Fees & shipping**. Buyers pay those prices. Compare them with what Shippo charges (shown on each order in the admin as "Label cost") so you don't lose money on shipping.
5. Pick-up points: the label is addressed to the shop's address. Only approve shops that have signed the partner agreement and agreed to receive parcels for customers. Set the pick-up shipping price so it covers the label plus the partner fee (Admin › Fees & shipping). Each month, pay partners from Admin › Pick-up points › Monthly statement.

## 5. Email

Use a transactional email provider (Postmark, Amazon SES, Mailgun, SendGrid):

1. Verify your sending domain and add the SPF and DKIM DNS records they give you. Without these, email lands in spam.
2. Set `MAIL_DRIVER=smtp`, `MAIL_FROM` (an address on your domain) and the `SMTP_*` settings.
3. Sign up with a new account and check that the confirmation email arrives. **Admin › Email outbox** shows every email and any error.

## 6. Background jobs & backups

Add to the server's crontab (`crontab -e`):

```cron
* * * * *  php /var/www/rethread/cron.php >> /var/log/rethread-cron.log 2>&1
15 3 * * * DB_USER=rethread DB_PASS='…' /var/www/rethread/scripts/backup.sh >> /var/log/rethread-backup.log 2>&1
```

`cron.php` sends email, releases abandoned checkouts, reminds sellers to ship (after 3 days), cancels and refunds unshipped orders (after 7 days), completes delivered orders once the buyer-protection window has passed, and cleans up old records.

Backups are written to `backups/`. **Copy them off the server every day**, for example `rclone copy backups remote:rethread-backups` to S3 or Backblaze B2. Try restoring one once so you know it works:
`gunzip -c backups/db-….sql.gz | mysql rethread_restore_test`.

## 7. Before you announce it

- [ ] Every item on **Admin › Go-live checklist** is green.
- [ ] Switch Stripe and Shippo to live keys and update the webhook secrets (live and test webhooks have different secrets).
- [ ] Do one real purchase with a cheap item between two of your own accounts, then refund it.
- [ ] Set up uptime monitoring (UptimeRobot, Better Stack) and error alerts (read the PHP error log, or add Sentry).
- [ ] Decide who answers `SUPPORT_EMAIL` and checks **Admin › Orders › Disputed** every day.

## Configuration reference

All settings live in `config.php`. Override them with environment variables or with `LOCAL_<NAME>` constants in `config.local.php`.

| Setting | What it does |
|---|---|
| `APP_ENV` | `production` hides error details and locks the installer |
| `APP_URL` | Public https address, used in emails and Stripe redirects |
| `APP_TIMEZONE` | Used by both PHP and MySQL (default `America/Los_Angeles`) |
| `FORCE_HTTPS` | Redirects http to https (on by default in production) |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_AUTOMATIC_TAX` | Payments; empty key = demo mode |
| `PAYMENT_HOLD_HOURS` | How long after delivery the buyer can report a problem (default 48) |
| `SHIPPO_API_KEY`, `SHIPPO_WEBHOOK_TOKEN` | Real labels and tracking; empty = demo labels |
| `MAIL_DRIVER`, `MAIL_FROM`, `SMTP_*` | Email sending (`log` = store only) |
| `ADMIN_2FA_REQUIRED` | Admins must use an authenticator app (default on) |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCK_MINUTES` | Sign-in lockout (default 5 tries per 15 minutes) |
| `CRON_TOKEN` | Lets you trigger `cron.php?token=…` over HTTPS if you can't use crontab |
| `COMPANY_*`, `SUPPORT_EMAIL`, `GOVERNING_STATE`, `LEGAL_REVIEWED` | Shown in the legal pages and emails |
