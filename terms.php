<?php
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/legal.php';
$app = e(APP_NAME);
$co = e(COMPANY_NAME);
legal_start('Terms of Service', 'November 10, 2026');
?>
<h2>1. Who we are</h2>
<p><?= $app ?> is an online marketplace operated by <?= $co ?> ("we", "us"). Members list second-hand items for sale and buy items from each other. We are not the seller of items listed by members, and we don't own, inspect or store them. The contract for each sale is between the buyer and the seller.</p>

<h2>2. Your account</h2>
<p>You must be at least 18 years old and able to form a binding contract. Give accurate information, keep your password secret, and tell us right away if you think someone else has used your account. You're responsible for activity on your account. One person, one account, unless we agree otherwise.</p>

<h2>3. Selling</h2>
<ul>
  <li>You may only list items you own and have the right to sell, described honestly, with your own photos.</li>
  <li>Items on our <a class="link" href="prohibited.php">prohibited items list</a> may not be listed.</li>
  <li>When an item sells, you must ship it within 5 days using the label we provide. If you don't, we may cancel the order and refund the buyer.</li>
  <li>To receive money you must set up payouts with our payment provider, Stripe, and accept the <a class="link" href="https://stripe.com/connect-account/legal" target="_blank" rel="noopener">Stripe Connected Account Agreement</a>. Stripe may ask for identity and tax information required by law.</li>
  <li>You're responsible for any income taxes on your sales. Stripe may issue you a Form 1099-K where the law requires it.</li>
</ul>

<h2>4. Buying</h2>
<ul>
  <li>The price you pay is the item price, plus a Buyer Protection fee, shipping and any applicable sales tax, all shown before you pay.</li>
  <li>Payments are processed by Stripe. We hold the payment until the order is complete.</li>
  <li>When your item arrives, check it and confirm, or report a problem within <?= (int)PAYMENT_HOLD_HOURS ?> hours of delivery. If you do neither, the order completes automatically and the seller is paid.</li>
</ul>

<h2>5. Buyer Protection</h2>
<p>If an order doesn't arrive, arrives damaged, or is significantly not as described, report it before the order completes. We'll review the evidence from both sides and may refund you in full, ask you to return the item first, or release payment to the seller. Buyer Protection doesn't cover buyer's remorse, items that match the description, or orders paid outside <?= $app ?>.</p>

<h2>6. Fees</h2>
<p>Listing is free. Buyers pay a Buyer Protection fee of <?= money(fee_fixed()) ?> plus <?= rtrim(rtrim(number_format(fee_rate() * 100, 2), '0'), '.') ?>% of the item price. Shipping is charged at the price shown at checkout. We may change fees with notice; changes don't affect orders already placed. See the <a class="link" href="help.php#fees">fee details</a>.</p>

<h2>7. Cancellations and refunds</h2>
<p>Either party may cancel an order before it ships, and the buyer is refunded in full. Refunds go back to the original payment method and usually take 5–10 business days to appear.</p>

<h2>8. Rules of conduct</h2>
<p>Don't: take transactions or payments off <?= $app ?>; post false, misleading or infringing content; harass, threaten or discriminate against others; manipulate reviews, prices or search; scrape the site or interfere with its operation; or use another person's account.</p>

<h2>9. Content and intellectual property</h2>
<p>You keep ownership of the photos and text you post, and you give us a worldwide, non-exclusive, royalty-free licence to host, display and promote them on <?= $app ?> while your listing is live. If you believe content infringes your copyright or trademark, email <?= e(SUPPORT_EMAIL) ?> with the details required by the DMCA, and we'll act on valid notices.</p>

<h2>10. Suspension and termination</h2>
<p>We may remove listings, hold payments, or suspend or close accounts that break these Terms or put other members at risk. You can delete your account at any time in Security settings, once your open orders are finished.</p>

<h2>11. Disclaimers and liability</h2>
<p><?= $app ?> is provided "as is". To the extent the law allows, we aren't liable for the items members sell, for indirect or consequential losses, and our total liability for any claim is limited to the greater of the fees you paid us in the 12 months before the claim or $100. Nothing in these Terms limits rights you have under consumer law that can't be waived.</p>

<h2>12. Changes and governing law</h2>
<p>We may update these Terms; we'll tell you about important changes by email or in the app before they take effect. These Terms are governed by the laws of the State of <?= e(GOVERNING_STATE) ?> and applicable US federal law.</p>
<?php legal_end();
