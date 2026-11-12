# Rethread

A mobile-first second-hand marketplace in plain PHP + MySQL, built for MAMP.
Its flows follow the reference app screenshots: browse, item page, checkout, orders, inbox, profile and selling. It has its own name and look.

## Run it

1. Start MAMP (Apache + MySQL).
2. Check `config.php`. The defaults are `127.0.0.1:3306`, user `root`, password `root`. If MAMP uses its own ports, set `DB_PORT` to `8889`.
3. Open http://localhost/vinted/install.php once. It creates the `rethread` database and demo data.
   - To rebuild everything from scratch, open `install.php?reset=1` or run `php install.php --reset`.
4. Open http://localhost/vinted/ (if Apache is on port 8888, use http://localhost:8888/vinted/).

### Demo accounts (test data only)

| Login | Password | Role |
|---|---|---|
| `demo@example.com` | `demo1234` | Buyer with orders, favorites and chats. Also sells 2 items. |
| `sam@example.com`, `maria@example.com`, `jordan@example.com`, `kate@example.com`, `dan@example.com` | `password123` | Sellers |
| `shop@example.com` | `password123` | Runs "The Corner Nursery" pick-up point. Opens the partner portal (Profile › My pick-up point). Order #2 is waiting there with code `482915`. |
| `admin@example.com` | `admin1234` | Admin: opens the back office at `/vinted/admin/`. On first visit you must set up two-step verification with an authenticator app. |

## Features

- **Browse**: a category tree (Men › Clothing › Jeans › Straight fit jeans…) and search. Filter by size, brand, condition, color and price, and sort the results.
- **Items**: photo gallery, favorites, a "Bumped" label, and the price shown with the fee included. Owners can edit, bump, reserve, hide or delete.
- **Checkout**: choose a pick-up point or home delivery (USPS / FedEx), with prices based on parcel size. Totals update live. The price summary lists order, bundle discount, Buyer Protection fee, shipping and sales tax. Payment is a demo: no card is charged.
- **Fees**: Buyer Protection fee = `$0.70 + 5%` of the item price (for example, $3.00 → $0.85 and $5.00 → $0.95). Admins change it under Fees & shipping; `config.php` holds the defaults.
- **Orders**: Sold and Bought tabs, plus In progress / Canceled / Completed filters. Status flow: Payment successful → Shipping label sent to seller → Shipped → Completed. The seller's wallet is credited when the buyer confirms. Either side can cancel before shipping. The buyer can report a problem, which suspends the order.
- **Offers and messages**: chat per item. When the seller accepts an offer, that buyer gets the item at the offer price.
- **Bundle discounts**: sellers set percentages for 2, 3 and 5+ items. Buyers pick several items on the seller's profile and check out once.
- **Profile**: wallet, orders, favorites, bundle discounts, vacation mode (hides your items), settings and address, password change, help.
- **Sell**: upload photos (JPG/PNG/WEBP/GIF, up to 8, checked server-side), plus category, brand, size, condition, colors, material, price and parcel size.

## Demo mode vs live mode

With no keys configured, the app runs in **demo mode**: checkout takes no real payment, labels are printable mock-ups, and emails are stored in **Admin › Email outbox** instead of being sent (that's where you'll find the confirmation link for a new account). Add Stripe, Shippo and SMTP settings to switch each part to the real service. **[DEPLOY.md](DEPLOY.md)** has the step-by-step guide, and **Admin › Go-live checklist** shows what's still missing.

What's built in for a live site:
- **Payments:** Stripe Connect. Buyers pay with card, Apple Pay or Google Pay through Stripe Checkout. The money is held until the buyer confirms, then transferred to the seller's bank. Refunds and chargebacks are handled too.
- **Shipping:** Shippo buys real USPS and FedEx labels. Tracking webhooks mark orders shipped and delivered. Orders complete automatically 48 hours after delivery.
- **Accounts:**
  - Email verification, which is required to buy, sell or message.
  - Password reset by email.
  - Login lockout after 5 wrong tries.
  - Two-step verification, required for admins.
- **Emails:** transactional email over SMTP, with an outbox and retries.
- **Privacy:**
  - Uploaded photos are resized and re-encoded, which strips GPS and other metadata.
  - Members can download their data and delete their account.
  - Draft Terms, Privacy Policy and prohibited items pages.
- **Server hardening:** HTTPS redirect, security headers, hardened session cookie, error details hidden in production, installer locked, internals blocked by `.htaccess`.
- **Background jobs** (`cron.php`): email sending, abandoned-checkout release, shipping reminders and auto-cancel, auto-complete.
- **Backups:** `scripts/backup.sh` for daily database and photo backups.

## Pick-up / drop-off network

- **Shops enrol themselves** at `partner_apply.php` (linked from Profile, Help and the partner agreement), or an admin adds them in **Admin › Pick-up points**. Applications wait there for approval.
- **Checkout** shows only active points that accept pick-ups, within 30 miles of the buyer, nearest first, with distance and hours. Full points are hidden. Addresses are located with the free US Census geocoder; when that fails, buyers are matched by ZIP.
- **Partner portal** (`partner.php`), used by shop staff on a phone:
  - Check in arriving parcels; the buyer is then sent a 6-digit code.
  - Hand parcels over after entering the code and checking photo ID.
  - Accept seller drop-offs.
  - Send uncollected parcels back.
  - Pause the shop, change hours and capacity, and see the month's earnings.
- **Buyer protection starts when the buyer collects the parcel**, not when it reaches the shop. Buyers get a reminder 2 days before the deadline. Parcels not collected within the hold period (7 days by default) are refunded to the buyer automatically and returned to the seller, with a return label when Shippo is connected.
- **Partner payouts:** Admin › Pick-up points › Monthly statement shows handovers × fee per shop, with CSV export. Pay partners outside the app.
- **Settings:** the partner fee and hold period are under Admin › Fees & shipping.

## Admin back office (`/admin/`)

Only accounts with the `admin` role can open it. Admins also see an **Admin panel** entry on their Profile page. Every action is written to an activity log that can't be edited.

- **Dashboard**: sales, fee revenue, open orders, disputes, members, listings, wallet balances owed, a 14-day daily sales chart, orders by status, latest orders and members.
- **Orders**: filter by status and search. In an order: release the payment to the seller, cancel and refund the buyer (optionally relisting the items), put the order on hold, or message the buyer and/or seller. Disputes ("I have a problem") wait here for a decision.
- **Members**: search and filter. A member's page shows their stats, orders and listings. Admins can ban or unban (banned members are signed out and their items disappear), grant or revoke admin, adjust the wallet with a reason, send a message, or end vacation mode.
- **Listings**: moderation with bulk actions. *Remove* tells the seller the reason and they can't undo it. *Restore* puts it back. *Delete* only works for items that were never ordered.
- **Categories**: add, rename, reorder and delete in the tree. A category that has listings can't be deleted.
- **Pick-up points**: approve or reject shop applications, add and edit points, assign a partner account, pause, set capacity, monthly partner statements.
- **Fees & shipping**: Buyer Protection fee (fixed + %), sales tax, and the carrier × parcel-size price grid. These override `config.php` and take effect immediately.
- **Messages**: send a support message to all members, sellers, buyers or one member. It arrives in their Inbox → Notifications.
- **Email outbox**: every email the site sends, with errors and a preview.
- **Go-live checklist**: checks your real configuration (HTTPS, keys, webhooks, email, cron, backups, admin 2FA, legal) and lists what's left.

If you installed before the admin existed, open `install.php` once. It adds the new columns and tables and creates the admin account without touching your data.

## Structure

```
config.php        DB + fee settings
install.php       schema + seed data
inc/app.php       DB, auth, CSRF, money/shipping helpers, HTTP hardening
inc/orders.php    every order state change (paid, release, refund, hold…)
inc/stripe.php    Stripe Connect client + webhook signatures
inc/shippo.php    label purchase + label refunds
inc/mail.php      email outbox + SMTP client
inc/security.php  tokens, rate limiting, TOTP 2FA
inc/images.php    photo resizing / metadata stripping
cron.php          background jobs (run every minute)
scripts/backup.sh daily backups
inc/layout.php    page shell, tab bar, item card
inc/icons.php     inline SVG icons
assets/           style.css, app.js, admin.css
admin/            back office (one file per screen, _inc.php = shared shell)
uploads/          user photos (script execution blocked via .htaccess)
placeholder.php   SVG product illustrations for demo items
*.php             one file per screen
```

Security basics: prepared statements, CSRF tokens on every form, `password_hash`, output escaping, upload re-encoding, rate limiting, 2FA, signed and idempotent webhooks. See DEPLOY.md before going live.
