<?php
require __DIR__ . '/inc/app.php';
require __DIR__ . '/inc/legal.php';
legal_start('Prohibited items', 'November 10, 2026');
?>
<p>To keep <?= e(APP_NAME) ?> safe and legal, you can't list the following. Listings that break these rules are removed, and repeated or serious violations can lead to a permanent ban.</p>
<ul>
  <li>Counterfeits, replicas or unauthorised copies of branded goods, or items that infringe someone's copyright or trademark.</li>
  <li>Weapons, ammunition, weapon parts and accessories, explosives, fireworks.</li>
  <li>Drugs, drug paraphernalia, prescription medicine, tobacco, vapes, alcohol and cannabis products (including CBD).</li>
  <li>Recalled products, and used cosmetics, used underwear or used swimwear bottoms (unless new with tags).</li>
  <li>Car seats, cribs and other safety-critical baby equipment.</li>
  <li>Stolen goods or items you don't have the right to sell.</li>
  <li>Hazardous materials: chemicals, flammable liquids, pressurised containers, batteries sold on their own.</li>
  <li>Animals, animal parts from protected species, ivory, fur from endangered species.</li>
  <li>Adult content and sexual services.</li>
  <li>Personal data, accounts, digital codes, gift cards, tickets, currency and financial instruments.</li>
  <li>Food and perishable goods.</li>
  <li>Items promoting hate, violence or discrimination.</li>
  <li>Anything illegal to sell or ship in the United States or the buyer's state.</li>
</ul>
<p>Also not allowed: listings that are only ads, photos not of the actual item, or offering to complete a sale outside <?= e(APP_NAME) ?>.</p>
<p>See something that breaks these rules? Email <?= e(SUPPORT_EMAIL) ?> with a link to the listing.</p>
<?php legal_end();
