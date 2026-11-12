<?php
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/legal.php';
$app = e(APP_NAME);
legal_start('Privacy Policy', 'November 10, 2026');
?>
<p>This policy explains what personal information <?= e(COMPANY_NAME) ?> collects when you use <?= $app ?>, why, who we share it with, and the choices you have.</p>

<h2>What we collect</h2>
<ul>
  <li><strong>Account details:</strong> username, email, full name, password (stored only as a secure hash), and optional bio.</li>
  <li><strong>Shipping details:</strong> address and phone number, used to create shipping labels and contact you about deliveries.</li>
  <li><strong>Listings and activity:</strong> photos, descriptions, favorites, messages, offers and orders. We remove location data (EXIF/GPS) from photos when you upload them.</li>
  <li><strong>Payments:</strong> handled by Stripe. We receive limited information (such as payment status and the last digits of a card); we never see or store full card numbers, bank account numbers or SSNs.</li>
  <li><strong>Technical data:</strong> IP address and login attempts, kept briefly to protect accounts against attacks; server logs.</li>
</ul>

<h2>How we use it</h2>
<p>To run the marketplace (accounts, listings, orders, payments, shipping, messages); to send service emails such as order updates and security alerts; to prevent fraud and enforce our Terms; to provide support; and to meet legal obligations such as tax and accounting rules. We don't sell your personal information and we don't use advertising trackers.</p>

<h2>Who we share it with</h2>
<ul>
  <li><strong>Other members:</strong> your username, listings and public profile. When you buy or sell, the other party sees what they need to complete the order (for example, the seller gets your shipping name and address on the label).</li>
  <li><strong>Stripe</strong> (payments and seller payouts), <strong>Shippo</strong> and the carriers (shipping labels and tracking), and our <strong>email provider</strong> (to deliver emails). They process data on our behalf or under their own privacy policies.</li>
  <li><strong>Authorities</strong> when the law requires it, or to protect the rights and safety of our members.</li>
</ul>

<h2>Cookies</h2>
<p>We use one essential cookie to keep you signed in. We don't use analytics or advertising cookies, so there's nothing to opt out of.</p>

<h2>How long we keep it</h2>
<p>We keep your account data while your account is open. When you delete your account we remove your profile, listings, photos and favorites. We keep order records (with personal contact details removed) for as long as tax and accounting laws require, usually 7 years. Login-attempt records are deleted after 30 days.</p>

<h2>Your rights</h2>
<p>You can download a copy of your data and delete your account at any time from <a class="link" href="security.php">Security settings</a>. You can correct your details in <a class="link" href="settings.php">Settings</a> and turn off notification emails there. Depending on where you live (for example California), you may have additional rights, such as to know what we collect and to not be discriminated against for using your rights. Email <?= e(SUPPORT_EMAIL) ?> to make a request.</p>

<h2>Security</h2>
<p>We use HTTPS, hashed passwords, optional two-step verification, rate limiting on sign-in and regular backups. No system is perfectly secure; if we learn of a breach affecting you, we'll notify you as the law requires.</p>

<h2>Children</h2>
<p><?= $app ?> is for people 18 and over. We don't knowingly collect information from children.</p>

<h2>Changes</h2>
<p>If we make important changes we'll tell you by email or in the app before they take effect.</p>
<?php legal_end();
