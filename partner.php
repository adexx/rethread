<?php
// Partner portal for pick-up / drop-off shops. Works on any phone at the counter.
require __DIR__ . '/inc/app.php';
$u = require_login();
$mine = partner_points((int)$u['id']);
if (!$mine) redirect('partner_apply.php');
$live = array_values(array_filter($mine, function ($p) { return in_array($p['status'], ['active', 'paused'], true); }));

$pid = (int)($_GET['p'] ?? ($live[0]['id'] ?? 0));
$point = null;
foreach ($live as $p) if ((int)$p['id'] === $pid) $point = $p;
$self = 'partner.php?p=' . $pid;

if (!$point) {
    page_start('Partner portal', ['back' => 'profile.php', 'tab' => 'profile']);
    echo '<div class="pad">';
    foreach ($mine as $p) {
        $msg = ['pending' => 'Your application is under review. We\'ll email you when it\'s approved.', 'rejected' => 'This application wasn\'t approved' . ($p['review_note'] ? ': ' . $p['review_note'] : '.')][$p['status']] ?? '';
        echo '<div class="box" style="margin:0 0 12px"><div class="box-title">' . icon('store', 22) . e($p['name']) . '</div><div class="box-line">' . e(point_address($p)) . '</div><div class="box-line accent">' . e($msg) . '</div></div>';
    }
    echo '</div>';
    page_end();
    exit;
}

$findOrder = function (string $ref, string $sqlStatus, bool $atThisPoint) use ($pid) {
    $ref = strtoupper(trim(preg_replace('/\s+/', '', $ref)));
    $ref = ltrim($ref, '#');
    if ($ref === '') return null;
    $where = $atThisPoint ? 'pickup_point_id = ? AND ' : '';
    $params = $atThisPoint ? [$pid] : [];
    $params[] = $ref;
    $params[] = ctype_digit($ref) ? (int)$ref : 0;
    return q("SELECT * FROM orders WHERE $where status IN ($sqlStatus) AND (UPPER(tracking) = ? OR id = ?) LIMIT 1", $params)->fetch() ?: null;
};

if (is_post()) {
    check_csrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'receive') {
        $o = $findOrder((string)($_POST['ref'] ?? ''), "'label_sent','shipped'", true);
        if (!$o) flash('No parcel for this shop with that tracking or order number. Check the label says ' . $point['name'] . '.', 'err');
        else { order_mark_at_point((int)$o['id'], (int)$u['id']); flash('Checked in order #' . $o['id'] . ' for ' . $o['ship_name'] . '. The buyer has been sent their code.'); }
    } elseif ($act === 'lookup') {
        if (rate_limited('pickup_code', (string)$pid, 10, 15)) { flash('Too many wrong codes. Wait 15 minutes.', 'err'); redirect($self); }
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        $o = strlen($code) === 6 ? q("SELECT * FROM orders WHERE pickup_point_id = ? AND status = 'at_pickup' AND pickup_code = ?", [$pid, $code])->fetch() : null;
        record_attempt('pickup_code', (string)$pid, (bool)$o);
        if (!$o) flash("That code doesn't match any parcel waiting here.", 'err');
        else { $_SESSION['handover'] = ['order' => (int)$o['id'], 'point' => $pid, 'at' => time()]; redirect($self . '#handover'); }
    } elseif ($act === 'handover') {
        $h = $_SESSION['handover'] ?? null;
        if (!$h || $h['point'] !== $pid || time() - $h['at'] > 600 || empty($_POST['id_checked'])) flash('Confirm you checked the photo ID.', 'err');
        else { order_mark_collected($h['order'], (int)$u['id']); unset($_SESSION['handover']); flash('Handed over order #' . $h['order'] . '. Thank you!'); }
    } elseif ($act === 'cancel_handover') {
        unset($_SESSION['handover']);
    } elseif ($act === 'dropoff') {
        if (!$point['accepts_dropoff']) { flash('Drop-off is turned off for this shop.', 'err'); redirect($self); }
        $o = $findOrder((string)($_POST['ref'] ?? ''), "'label_sent'", false);
        if (!$o) flash('No parcel waiting to ship with that tracking or order number.', 'err');
        else { order_mark_dropped_off((int)$o['id'], $pid, (int)$u['id']); flash('Drop-off recorded for order #' . $o['id'] . '. Give it to the carrier at the next collection.'); }
    } elseif ($act === 'returned') {
        $oid = (int)($_POST['order'] ?? 0);
        $o = q('SELECT * FROM orders WHERE id = ? AND pickup_point_id = ? AND return_due = 1 AND returned_at IS NULL', [$oid, $pid])->fetch();
        if ($o) {
            q('UPDATE orders SET returned_at = NOW() WHERE id = ?', [$oid]);
            pudo_event($pid, $oid, 'returned', (int)$u['id']);
            notify((int)$o['seller_id'], 'Your uncollected parcel (order #' . $oid . ') was handed to the carrier and is on its way back to you.', 'order.php?id=' . $oid);
            flash('Return recorded.');
        }
    } elseif ($act === 'settings') {
        $hours = mb_substr(trim((string)($_POST['hours'] ?? '')), 0, 120);
        $status = !empty($_POST['paused']) ? 'paused' : 'active';
        $cap = max(5, min(500, (int)($_POST['capacity'] ?? $point['capacity'])));
        q('UPDATE pickup_points SET hours = ?, status = ?, capacity = ? WHERE id = ?', [$hours ?: $point['hours'], $status, $cap, $pid]);
        flash($status === 'paused' ? 'Paused: buyers can\'t choose your shop until you resume. Parcels already on their way will still arrive.' : 'Saved.');
    }
    redirect($self);
}

$incoming = q("SELECT id, ship_name, tracking, status, shipped_at FROM orders WHERE pickup_point_id = ? AND status IN ('label_sent','shipped') ORDER BY id", [$pid])->fetchAll();
$waiting = q("SELECT id, ship_name, at_point_at, pickup_deadline FROM orders WHERE pickup_point_id = ? AND status = 'at_pickup' ORDER BY pickup_deadline", [$pid])->fetchAll();
$returns = q('SELECT id, ship_name, return_tracking, return_label_url FROM orders WHERE pickup_point_id = ? AND return_due = 1 AND returned_at IS NULL', [$pid])->fetchAll();
$month = q("SELECT type, COUNT(*) n FROM pudo_events WHERE point_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY type", [$pid])->fetchAll(PDO::FETCH_KEY_PAIR);
$history = q("SELECT e.*, o.ship_name FROM pudo_events e LEFT JOIN orders o ON o.id = e.order_id WHERE e.point_id = ? ORDER BY e.id DESC LIMIT 15", [$pid])->fetchAll();
$h = $_SESSION['handover'] ?? null;
$handover = ($h && $h['point'] === $pid && time() - $h['at'] <= 600) ? q('SELECT id, ship_name FROM orders WHERE id = ? AND status = ?', [$h['order'], 'at_pickup'])->fetch() : null;
$labels = ['received' => 'Checked in', 'handover' => 'Handed to buyer', 'dropoff' => 'Seller drop-off', 'return_due' => 'Return due', 'returned' => 'Returned to carrier'];

page_start($point['name'], ['back' => 'profile.php', 'tab' => 'profile']);
?>
<?php if (count($live) > 1): ?>
  <div class="chips"><?php foreach ($live as $p): ?><a class="chip<?= (int)$p['id'] === $pid ? ' on' : '' ?>" href="partner.php?p=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($point['status'] === 'paused'): ?><div class="flash flash-err">Paused: buyers can't choose this shop right now.</div><?php endif; ?>

<div class="top-cats" style="grid-template-columns:repeat(4,1fr);margin-top:12px">
  <a href="#waiting"><strong style="font-size:22px"><?= count($waiting) ?></strong><span class="small">Waiting</span></a>
  <a href="#incoming"><strong style="font-size:22px"><?= count($incoming) ?></strong><span class="small">On the way</span></a>
  <a href="#returns"><strong style="font-size:22px"><?= count($returns) ?></strong><span class="small">Returns</span></a>
  <a href="#history"><strong style="font-size:22px"><?= money(($month['handover'] ?? 0) * pudo_fee()) ?></strong><span class="small">This month</span></a>
</div>

<h2 class="section-title" id="handover">Customer collecting</h2>
<?php if ($handover): ?>
  <div class="box" style="border-color:var(--accent)">
    <div class="box-title"><?= icon('user', 22) ?><?= e($handover['ship_name']) ?></div>
    <div class="box-line">Order #<?= (int)$handover['id'] ?>. Check their photo ID shows this name before handing over the parcel.</div>
    <form method="post" class="mt-16"><?= csrf_field() ?>
      <label class="row" style="margin-bottom:12px"><input type="checkbox" name="id_checked" value="1" required style="width:20px;height:20px;accent-color:var(--accent)">The name on their ID matches</label>
      <div class="btn-row"><button class="btn" name="action" value="cancel_handover" formnovalidate>Cancel</button><button class="btn btn-primary" name="action" value="handover">Hand over</button></div>
    </form>
  </div>
<?php else: ?>
  <form method="post" class="pad" style="padding-top:0"><?= csrf_field() ?><input type="hidden" name="action" value="lookup">
    <div class="inline-form"><input class="input" name="code" inputmode="numeric" maxlength="7" placeholder="6-digit pick-up code" required style="flex:1;font-size:20px;letter-spacing:4px" aria-label="Pick-up code"><button class="btn btn-primary" style="width:auto">Find</button></div>
  </form>
<?php endif; ?>

<h2 class="section-title">Parcel arrived from carrier</h2>
<form method="post" class="pad" style="padding-top:0"><?= csrf_field() ?><input type="hidden" name="action" value="receive">
  <div class="inline-form"><input class="input" name="ref" placeholder="Tracking number or order #" required style="flex:1" aria-label="Tracking or order number"><button class="btn btn-outline" style="width:auto">Check in</button></div>
</form>

<?php if ($point['accepts_dropoff']): ?>
<h2 class="section-title">Seller dropping off</h2>
<form method="post" class="pad" style="padding-top:0"><?= csrf_field() ?><input type="hidden" name="action" value="dropoff">
  <div class="inline-form"><input class="input" name="ref" placeholder="Tracking number on the label" required style="flex:1" aria-label="Tracking number"><button class="btn btn-outline" style="width:auto">Accept</button></div>
  <p class="hint">Only accept sealed parcels with a printed label.</p>
</form>
<?php endif; ?>

<h2 class="section-title" id="waiting">Waiting for buyers (<?= count($waiting) ?>)</h2>
<?php if (!$waiting): ?><p class="pad muted" style="padding-top:0">No parcels waiting.</p><?php endif; ?>
<?php foreach ($waiting as $w): $soon = strtotime($w['pickup_deadline']) < time() + 2 * 86400; ?>
  <div class="order-row"><span class="o-main"><div class="o-title"><?= e($w['ship_name']) ?></div><div class="o-sub">Order #<?= (int)$w['id'] ?> · arrived <?= e(time_ago($w['at_point_at'])) ?></div></span>
    <span class="small <?= $soon ? 'danger' : 'muted' ?>" style="text-align:right">until<br><?= e(date('M j', strtotime($w['pickup_deadline']))) ?></span></div>
<?php endforeach; ?>

<h2 class="section-title" id="incoming">On the way to you (<?= count($incoming) ?>)</h2>
<?php if (!$incoming): ?><p class="pad muted" style="padding-top:0">Nothing on the way.</p><?php endif; ?>
<?php foreach ($incoming as $i): ?>
  <div class="order-row"><span class="o-main"><div class="o-title"><?= e($i['ship_name']) ?></div><div class="o-sub">Order #<?= (int)$i['id'] ?> · <?= e($i['tracking'] ?: 'label not printed yet') ?></div></span>
    <span class="muted small"><?= $i['status'] === 'shipped' ? 'In transit' : 'Not shipped yet' ?></span></div>
<?php endforeach; ?>

<h2 class="section-title" id="returns">Returns to send back (<?= count($returns) ?>)</h2>
<?php if (!$returns): ?><p class="pad muted" style="padding-top:0">No returns.</p><?php endif; ?>
<?php foreach ($returns as $r): ?>
  <div class="order-row"><span class="o-main"><div class="o-title"><?= e($r['ship_name']) ?> · #<?= (int)$r['id'] ?></div>
      <div class="o-sub"><?php if ($r['return_label_url']): ?><a class="link" href="<?= e($r['return_label_url']) ?>" target="_blank" rel="noopener">Print return label</a><?php else: ?>Return label: <?= e($r['return_tracking']) ?> (demo)<?php endif; ?></div></span>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="returned"><input type="hidden" name="order" value="<?= (int)$r['id'] ?>"><button class="btn btn-outline btn-sm">Given to carrier</button></form></div>
<?php endforeach; ?>

<h2 class="section-title" id="history">This month</h2>
<div class="summary">
  <?php foreach ($labels as $k => $l): ?><div><span><?= e($l) ?></span><span><?= (int)($month[$k] ?? 0) ?></span></div><?php endforeach; ?>
  <div style="font-size:19px"><span style="color:var(--text)">Earnings (<?= money(pudo_fee()) ?> per handover)</span><span><?= money(($month['handover'] ?? 0) * pudo_fee()) ?></span></div>
</div>
<?php if ($history): ?>
  <p class="pad muted small" style="margin:0;padding-bottom:4px">Recent activity</p>
  <?php foreach ($history as $ev): ?>
    <div class="order-row" style="padding:8px 16px"><span class="o-main small"><?= e($labels[$ev['type']] ?? $ev['type']) ?><?= $ev['order_id'] ? ' · #' . (int)$ev['order_id'] : '' ?><?= $ev['ship_name'] ? ' · ' . e($ev['ship_name']) : '' ?></span><span class="muted small"><?= e(time_ago($ev['created_at'])) ?></span></div>
  <?php endforeach; ?>
<?php endif; ?>

<h2 class="section-title">Shop settings</h2>
<form method="post" class="pad" style="padding-top:0"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <label class="field"><span>Opening hours (shown to buyers)</span><input class="input" name="hours" maxlength="120" value="<?= e($point['hours']) ?>"></label>
  <label class="field"><span>Parcel capacity</span><input class="input" type="number" name="capacity" min="5" max="500" value="<?= (int)$point['capacity'] ?>"></label>
  <label class="row" style="justify-content:space-between;margin-bottom:14px"><span>Pause (closed, on holiday, full)</span><span class="switch"><input type="checkbox" name="paused" value="1" <?= $point['status'] === 'paused' ? 'checked' : '' ?>><span></span></span></label>
  <button class="btn btn-primary">Save</button>
  <p class="hint"><?= e(point_address($point)) ?>. To change the address, contact <?= e(SUPPORT_EMAIL) ?>.</p>
</form>
<?php page_end();
