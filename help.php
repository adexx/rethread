<?php
require __DIR__ . '/inc/app.php';
$ex = [3.00, 5.00, 9.99, 25.00];
page_start('Help Center', ['back' => true, 'tab' => user() ? 'profile' : null]);
?>
<div class="pad" style="line-height:1.55">
  <h2 id="about" style="margin-top:0">How <?= e(APP_NAME) ?> works</h2>
  <p>Sellers list items for free and keep 100% of the price. Buyers pay the item price, a Buyer Protection fee and shipping. The seller is paid once the buyer confirms everything is OK.</p>

  <h3 id="fees">Buyer Protection fee</h3>
  <p class="muted"><?= money(fee_fixed()) ?> + <?= fee_rate() * 100 ?>% of the item price (after any bundle discount).</p>
  <div class="summary" style="padding:0">
    <?php foreach ($ex as $p): ?><div><span>Item <?= money($p) ?></span><span><?= money(buyer_fee($p)) ?> fee → <?= money(price_incl($p)) ?></span></div><?php endforeach; ?>
  </div>
  <p class="muted small">Sales tax is <?= tax_rate() * 100 ?>% in this demo.</p>

  <h3 id="protection">Buyer Protection</h3>
  <p>If an item doesn't arrive, or is significantly not as described, tap <em>I have a problem</em> on the order before confirming. The order is suspended and the payment is held until it's resolved.</p>

  <h3 id="shipping">Shipping</h3>
  <div class="summary" style="padding:0">
    <div><span>Parcel</span><span>Pick-up · USPS · FedEx</span></div>
    <?php foreach (PARCEL_SIZES as $k => [$n]): ?>
      <div><span><?= e($n) ?></span><span><?= money(shipping_cost('pickup', $k)) ?> · <?= money(shipping_cost('usps', $k)) ?> · <?= money(shipping_cost('fedex', $k)) ?></span></div>
    <?php endforeach; ?>
  </div>
  <p class="muted small">Bundles ship as one parcel, priced by the largest item.</p>

  <h3 id="pickup">Pick-up points</h3>
  <p>Choose a pick-up point at checkout and collect your parcel at a local shop at a time that suits you. When it arrives we send you a 6-digit code; show it with a photo ID at the counter. Parcels are held for <?= pickup_hold_days() ?> days, then returned to the seller and you're refunded. Own a shop? <a class="link" href="partner_apply.php">Become a pick-up point</a> and earn <?= money(pudo_fee()) ?> per parcel.</p>

  <h3 id="bundles">Bundle discounts</h3>
  <p>Sellers can offer a discount when you buy several of their items at once. Open the seller's profile and tap <em>Shop bundles</em>.</p>

  <h3>Offers</h3>
  <p>Tap <em>Make an offer</em> on a listing. If the seller accepts, the item's price drops to your offer for you at checkout.</p>
</div>
<?php page_end();
