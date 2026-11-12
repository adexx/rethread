<?php
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/legal.php';
$app = e(APP_NAME);
legal_start('Pick-up point partner agreement', 'November 10, 2026');
?>
<p>This agreement is between <?= e(COMPANY_NAME) ?> and the business that applies to be a <?= $app ?> pick-up / drop-off point ("Partner").</p>
<h2>1. What the Partner does</h2>
<ul>
  <li>Accept parcels addressed to the Partner's location for <?= $app ?> buyers, and check each one in using the partner portal the same day.</li>
  <li>Store parcels safely, out of public reach and protected from weather, for up to <?= pickup_hold_days() ?> days.</li>
  <li>Hand a parcel over only to a person who gives the correct pick-up code <strong>and</strong> shows photo ID matching the name on the parcel, and record the handover in the portal.</li>
  <li>If drop-off is enabled, accept sealed, labelled parcels from sellers, scan them in the portal, and give them to the carrier at the next collection.</li>
  <li>Hand uncollected parcels back to the carrier with the return label provided.</li>
  <li>Keep the opening hours shown in the app up to date, and pause the location in the portal when closed for more than a day.</li>
</ul>
<h2>2. Fees</h2>
<p><?= $app ?> pays the Partner <?= money(pudo_fee()) ?> for each parcel handed over to a buyer, paid monthly based on the handovers recorded in the portal. We may change the fee with 30 days' notice.</p>
<h2>3. Customer data</h2>
<p>The Partner only sees what it needs (the buyer's name, order number and tracking number) and may use it only to handle parcels. Don't copy, keep or share it for any other purpose.</p>
<h2>4. Lost or damaged parcels</h2>
<p>Report damaged, lost or suspicious parcels to <?= e(SUPPORT_EMAIL) ?> the same day. The Partner is responsible for parcels lost or damaged through its own negligence while in its care, up to the value of the order.</p>
<h2>5. Ending the agreement</h2>
<p>Either side can end this agreement with 14 days' written notice. We may suspend a location immediately if parcels go missing or handover rules aren't followed. Parcels in the Partner's care at the end must be handed to buyers or returned to the carrier as instructed.</p>
<h2>6. Independent business</h2>
<p>The Partner is an independent business, not an employee or agent of <?= e(COMPANY_NAME) ?>, and is responsible for its own staff, insurance and taxes.</p>
<?php legal_end();
