<?php
require __DIR__ . '/_inc.php';
require_admin();

$keys = ['fee_fixed', 'fee_rate', 'tax_rate'];
foreach (CARRIERS as $c => $_) foreach (PARCEL_SIZES as $s => $__) $keys[] = "ship_{$c}_{$s}";

if (is_post()) {
    check_csrf();
    $errors = [];
    $vals = [];
    $num = function (string $k) { return trim((string)($_POST[$k] ?? '')); };
    $fixed = $num('fee_fixed'); $rate = $num('fee_rate'); $tax = $num('tax_rate');
    if (!is_numeric($fixed) || $fixed < 0 || $fixed > 50) $errors[] = 'Fixed fee must be between $0 and $50.';
    if (!is_numeric($rate) || $rate < 0 || $rate > 50) $errors[] = 'Fee percentage must be between 0 and 50.';
    if (!is_numeric($tax) || $tax < 0 || $tax > 30) $errors[] = 'Sales tax must be between 0 and 30%.';
    $vals['fee_fixed'] = round((float)$fixed, 2);
    $vals['fee_rate'] = round((float)$rate / 100, 4);
    $vals['tax_rate'] = round((float)$tax / 100, 4);
    $pf = $num('pudo_fee'); $hd = $num('pickup_hold_days');
    if (!is_numeric($pf) || $pf < 0 || $pf > 20) $errors[] = 'Partner fee must be between $0 and $20.';
    if (!ctype_digit($hd) || (int)$hd < 2 || (int)$hd > 30) $errors[] = 'Pick-up hold must be 2–30 days.';
    $vals['pudo_fee'] = round((float)$pf, 2);
    $vals['pickup_hold_days'] = (int)$hd;
    foreach (CARRIERS as $c => $_) foreach (PARCEL_SIZES as $s => $__) {
        $v = $num("ship_{$c}_{$s}");
        if (!is_numeric($v) || $v < 0 || $v > 100) { $errors[] = 'Shipping prices must be between $0 and $100.'; break 2; }
        $vals["ship_{$c}_{$s}"] = round((float)$v, 2);
    }
    if ($errors) {
        flash(implode(' ', array_unique($errors)), 'err');
    } else {
        $changes = [];
        foreach ($vals as $k => $v) {
            $defaults = ['fee_fixed' => FEE_FIXED, 'fee_rate' => FEE_RATE, 'tax_rate' => SALES_TAX_RATE, 'pudo_fee' => pudo_fee(), 'pickup_hold_days' => pickup_hold_days()];
            $old = (float)setting($k, $defaults[$k] ?? shipping_cost(explode('_', $k)[1], explode('_', $k)[2]));
            if (abs($old - $v) > 0.00001) $changes[] = "$k: $old → $v";
            q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, (string)$v]);
        }
        if ($changes) admin_log('update_fees', 'settings', null, implode('; ', $changes));
        flash($changes ? 'Saved. New prices apply to orders placed from now on.' : 'No changes.');
    }
    redirect('settings.php');
}

$examples = [3.00, 5.00, 9.99, 25.00, 100.00];
admin_start('Fees & shipping', 'settings');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="cols">
    <section class="panel">
      <h2>Buyer Protection fee</h2>
      <p class="panel-sub">Charged to the buyer on top of the item price: fixed amount + percentage of the item value (after bundle discount). Sellers keep the full price.</p>
      <div class="inline-form">
        <label class="field" style="margin:0;flex:1"><span>Fixed ($)</span><input class="input" type="number" step="0.01" min="0" name="fee_fixed" value="<?= e(number_format(fee_fixed(), 2, '.', '')) ?>" required></label>
        <label class="field" style="margin:0;flex:1"><span>Percentage (%)</span><input class="input" type="number" step="0.01" min="0" name="fee_rate" value="<?= e(rtrim(rtrim(number_format(fee_rate() * 100, 2, '.', ''), '0'), '.')) ?>" required></label>
        <label class="field" style="margin:0;flex:1"><span>Sales tax (%)</span><input class="input" type="number" step="0.01" min="0" name="tax_rate" value="<?= e(rtrim(rtrim(number_format(tax_rate() * 100, 2, '.', ''), '0'), '.')) ?>" required></label>
      </div>
      <table class="tbl mt-16">
        <thead><tr><th>Item price</th><th class="num">Fee</th><th class="num">Buyer sees</th></tr></thead><tbody>
        <?php foreach ($examples as $p): ?><tr><td><?= money($p) ?></td><td class="num"><?= money(buyer_fee($p)) ?></td><td class="num"><?= money(price_incl($p)) ?> incl.</td></tr><?php endforeach; ?>
      </tbody></table>
      <p class="note">Examples use the saved values.</p>
    </section>
    <section class="panel">
      <h2>Shipping prices</h2>
      <p class="panel-sub">What the buyer pays per carrier and parcel size. Bundles use the largest item's size.</p>
      <div class="tbl-wrap"><table class="tbl matrix">
        <thead><tr><th>Carrier</th><?php foreach (PARCEL_SIZES as $s => [$n]): ?><th class="num"><?= e($n) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (CARRIERS as $c => $info): ?>
          <tr><td><?= e($info['name']) ?></td>
            <?php foreach (PARCEL_SIZES as $s => $_): ?><td class="num"><input class="input" type="number" step="0.01" min="0" name="ship_<?= $c ?>_<?= $s ?>" value="<?= e(number_format(shipping_cost($c, $s), 2, '.', '')) ?>" aria-label="<?= e($info['name'] . ' ' . $s) ?>" required></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </section>
  </div>
  <section class="panel">
    <h2>Pick-up points</h2>
    <div class="inline-form">
      <label class="field" style="margin:0;flex:1"><span>Partner fee per parcel handed over ($)</span><input class="input" type="number" step="0.01" min="0" name="pudo_fee" value="<?= e(number_format(pudo_fee(), 2, '.', '')) ?>" required></label>
      <label class="field" style="margin:0;flex:1"><span>Days a parcel is held before return</span><input class="input" type="number" min="2" max="30" name="pickup_hold_days" value="<?= pickup_hold_days() ?>" required></label>
    </div>
    <p class="note">Tip: a label to a shop costs about the same as home delivery. Set the pick-up shipping price above so it covers the label plus this partner fee.</p>
  </section>
  <button class="btn btn-primary" style="width:auto" data-confirm="Save new fees and shipping prices? They apply to new orders immediately.">Save changes</button>
</form>
<?php admin_end();
