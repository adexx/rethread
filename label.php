<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$id = (int)($_GET['id'] ?? 0);
$o = q('SELECT * FROM orders WHERE id = ? AND seller_id = ?', [$id, $u['id']])->fetch();
if (!$o || !$o['tracking']) redirect('order.php?id=' . $id);
$point = $o['pickup_point_id'] ? q('SELECT * FROM pickup_points WHERE id = ?', [$o['pickup_point_id']])->fetch() : null;
$carrier = CARRIERS[$o['carrier']];

page_start('Shipping label', ['back' => 'order.php?id=' . $id, 'nav' => false]);
?>
<?php if ($o['label_url']): ?>
  <div class="pad">
    <div class="box" style="margin:0 0 16px">
      <div class="box-title"><?= icon('truck', 22) ?>Your label is ready</div>
      <div class="box-line">Tracking <?= e($o['tracking']) ?>. Print the 4×6" label, tape it to the parcel so the barcode is flat, and drop it off with the carrier.</div>
    </div>
    <a class="btn btn-primary" href="<?= e($o['label_url']) ?>" target="_blank" rel="noopener">Open label (PDF)</a>
    <a class="btn btn-outline mt-16" href="order.php?id=<?= $id ?>">Back to order</a>
  </div>
<?php else: ?>
<div class="label-sheet">
  <div style="display:flex;justify-content:space-between"><strong><?= e($carrier['name']) ?></strong><strong>PARCEL <?= e($o['parcel_size']) ?></strong></div>
  <div class="barcode" aria-hidden="true"></div>
  <div style="font-size:20px;letter-spacing:2px;text-align:center"><?= e($o['tracking']) ?></div>
  <hr>
  <div><small>FROM</small><br><?= e($u['full_name'] ?: $u['username']) ?><br><?= e($u['address_line']) ?><br><?= e(trim($u['zip'] . ' ' . $u['city'] . ' ' . $u['state'])) ?></div>
  <hr>
  <div><small>TO</small><br>
    <?php if ($point): ?>
      <?= e($o['ship_name']) ?> c/o <?= e($point['name']) ?><br><?= e($point['address']) ?><br><?= e($point['zip'] . ' ' . $point['city']) ?>
    <?php else: ?>
      <?= e($o['ship_name']) ?><br><?= e($o['ship_address']) ?><br><?= e(trim($o['ship_zip'] . ' ' . $o['ship_city'] . ' ' . $o['ship_state'])) ?>
    <?php endif; ?>
  </div>
  <hr>
  <small>Order #<?= (int)$o['id'] ?> · DEMO LABEL, not valid for shipping. Connect Shippo to print real labels.</small>
</div>
<div class="pad no-print" style="display:grid;gap:10px">
  <button class="btn btn-primary" onclick="window.print()">Print label</button>
  <a class="btn btn-outline" href="order.php?id=<?= $id ?>">Back to order</a>
</div>
<?php endif; ?>
<?php page_end();
