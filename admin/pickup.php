<?php
require __DIR__ . '/_inc.php';
require_admin();

$tab = in_list((string)($_GET['tab'] ?? 'active'), ['active', 'pending', 'rejected', 'statement'], 'active');
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$fields = ['name' => 120, 'address' => 190, 'city' => 80, 'state' => 2, 'zip' => 5, 'hours' => 120, 'contact_name' => 120, 'contact_phone' => 30, 'contact_email' => 190, 'notes' => 500];

// ---- CSV statement download ----
if ($tab === 'statement' && isset($_GET['csv'])) {
    $rows = q("SELECT p.id, p.name, p.city, p.state, u.username, p.contact_email,
                 SUM(e.type = 'handover') handovers, SUM(e.type = 'received') received, SUM(e.type = 'dropoff') dropoffs, SUM(e.type = 'returned') returned
               FROM pickup_points p LEFT JOIN users u ON u.id = p.partner_user_id
               JOIN pudo_events e ON e.point_id = p.id AND DATE_FORMAT(e.created_at, '%Y-%m') = ?
               GROUP BY p.id ORDER BY p.name", [$month])->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pickup-statement-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['point_id', 'name', 'city', 'state', 'partner', 'email', 'handovers', 'received', 'dropoffs', 'returned', 'fee_per_handover', 'amount_due']);
    foreach ($rows as $r) fputcsv($out, [$r['id'], $r['name'], $r['city'], $r['state'], $r['username'], $r['contact_email'], $r['handovers'], $r['received'], $r['dropoffs'], $r['returned'], pudo_fee(), number_format($r['handovers'] * pudo_fee(), 2, '.', '')]);
    admin_log('export_pudo_statement', 'pickup', null, $month);
    exit;
}

if (is_post()) {
    check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $p = $id ? q('SELECT * FROM pickup_points WHERE id = ?', [$id])->fetch() : null;
    $back = 'pickup.php?tab=' . $tab;

    if (in_array($act, ['add', 'save'], true)) {
        $v = [];
        foreach ($fields as $k => $max) $v[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $v['state'] = strtoupper($v['state']);
        $cap = max(5, min(500, (int)($_POST['capacity'] ?? 30)));
        $pick = !empty($_POST['accepts_pickup']) ? 1 : 0;
        $drop = !empty($_POST['accepts_dropoff']) ? 1 : 0;
        $partnerId = null;
        $who = trim((string)($_POST['partner'] ?? ''));
        if ($who !== '') {
            $partnerId = q('SELECT id FROM users WHERE (username = ? OR email = ?) AND deleted_at IS NULL', [$who, $who])->fetchColumn() ?: null;
            if (!$partnerId) { flash('No member "' . $who . '". Leave Partner empty if the shop has no account yet.', 'err'); redirect($back . ($id ? '&edit=' . $id : '')); }
        }
        if ($v['name'] === '' || $v['address'] === '' || $v['city'] === '' || !preg_match('/^[A-Z]{2}$/', $v['state']) || !preg_match('/^\d{5}$/', $v['zip'])) {
            flash('Name, address, city, 2-letter state and 5-digit ZIP are required.', 'err');
            redirect($back . ($id ? '&edit=' . $id : '&new=1'));
        }
        $lat = trim((string)($_POST['lat'] ?? ''));
        $lng = trim((string)($_POST['lng'] ?? ''));
        $addressChanged = !$p || $p['address'] !== $v['address'] || $p['zip'] !== $v['zip'] || $p['city'] !== $v['city'];
        if ((!is_numeric($lat) || !is_numeric($lng)) || ($addressChanged && !empty($_POST['regeocode']))) {
            $ll = geocode_address($v['address'], $v['city'], $v['state'], $v['zip']);
            [$lat, $lng] = $ll ?: [null, null];
        }
        $params = array_merge(array_values($v), [$lat !== '' ? $lat : null, $lng !== '' ? $lng : null, $cap, $pick, $drop, $partnerId]);
        if ($act === 'add') {
            q("INSERT INTO pickup_points (name, address, city, state, zip, hours, contact_name, contact_phone, contact_email, notes, lat, lng, capacity, accepts_pickup, accepts_dropoff, partner_user_id, status, applied_at, approved_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'active', NOW(), NOW())", $params);
            $id = (int)db()->lastInsertId();
            admin_log('add_pickup_point', 'pickup', $id, $v['name']);
        } elseif ($p) {
            q('UPDATE pickup_points SET name=?, address=?, city=?, state=?, zip=?, hours=?, contact_name=?, contact_phone=?, contact_email=?, notes=?, lat=?, lng=?, capacity=?, accepts_pickup=?, accepts_dropoff=?, partner_user_id=? WHERE id = ?', array_merge($params, [$id]));
            admin_log('edit_pickup_point', 'pickup', $id, $v['name']);
        }
        flash($lat === null || $lat === '' ? 'Saved, but the address couldn\'t be located. Buyers are matched by ZIP until you add latitude/longitude.' : 'Saved.', $lat === null || $lat === '' ? 'err' : 'ok');
        redirect($back);
    }
    if (!$p) redirect($back);
    if ($act === 'approve' && $p['status'] === 'pending') {
        $ll = ($p['lat'] === null) ? geocode_address($p['address'], $p['city'], $p['state'], $p['zip']) : [$p['lat'], $p['lng']];
        q("UPDATE pickup_points SET status = 'active', approved_at = NOW(), lat = ?, lng = ? WHERE id = ?", [$ll[0] ?? null, $ll[1] ?? null, $id]);
        if ($p['partner_user_id']) {
            notify((int)$p['partner_user_id'], 'Welcome aboard! ' . $p['name'] . ' is now a ' . APP_NAME . ' pick-up point. Open the partner portal to start checking in parcels.', 'partner.php?p=' . $id);
        }
        admin_log('approve_pickup_point', 'pickup', $id, $p['name']);
        flash($p['name'] . ' approved and live at checkout' . ($ll ? '.' : ' (address not located; buyers matched by ZIP).'));
    } elseif ($act === 'reject' && $p['status'] === 'pending') {
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        q("UPDATE pickup_points SET status = 'rejected', review_note = ? WHERE id = ?", [$note, $id]);
        if ($p['partner_user_id']) notify((int)$p['partner_user_id'], 'Your pick-up point application for ' . $p['name'] . ' wasn\'t approved' . ($note ? ': ' . $note : '.'), 'partner.php');
        admin_log('reject_pickup_point', 'pickup', $id, $p['name'] . ($note ? ' — ' . $note : ''));
        flash('Application rejected.');
    } elseif (in_array($act, ['pause', 'resume'], true) && in_array($p['status'], ['active', 'paused'], true)) {
        q('UPDATE pickup_points SET status = ? WHERE id = ?', [$act === 'pause' ? 'paused' : 'active', $id]);
        if ($p['partner_user_id']) notify((int)$p['partner_user_id'], $p['name'] . ($act === 'pause' ? ' was paused by support; buyers can\'t choose it for now.' : ' is live again.'), 'partner.php?p=' . $id, false);
        admin_log($act . '_pickup_point', 'pickup', $id, $p['name']);
        flash($act === 'pause' ? 'Paused. Parcels already on their way still arrive there.' : 'Resumed.');
    } elseif ($act === 'delete') {
        $used = (int)q('SELECT COUNT(*) FROM orders WHERE pickup_point_id = ? OR dropoff_point_id = ?', [$id, $id])->fetchColumn();
        if ($used) flash("Can't delete: $used order(s) used this point. Pause it instead.", 'err');
        else { q('DELETE FROM pickup_points WHERE id = ?', [$id]); admin_log('delete_pickup_point', 'pickup', $id, $p['name']); flash('Deleted.'); }
    }
    redirect($back);
}

$counts = q('SELECT status, COUNT(*) FROM pickup_points GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$edit = (int)($_GET['edit'] ?? 0);
$current = $edit ? q('SELECT p.*, u.username partner_name FROM pickup_points p LEFT JOIN users u ON u.id = p.partner_user_id WHERE p.id = ?', [$edit])->fetch() : null;
$showForm = $current || isset($_GET['new']);

admin_start('Pick-up points', 'pickup');
?>
<div class="toolbar">
  <div class="seg">
    <a class="<?= $tab === 'active' ? 'on' : '' ?>" href="pickup.php?tab=active">Live<em><?= (int)($counts['active'] ?? 0) + (int)($counts['paused'] ?? 0) ?></em></a>
    <a class="<?= $tab === 'pending' ? 'on' : '' ?>" href="pickup.php?tab=pending">Applications<em><?= (int)($counts['pending'] ?? 0) ?></em></a>
    <a class="<?= $tab === 'rejected' ? 'on' : '' ?>" href="pickup.php?tab=rejected">Rejected<em><?= (int)($counts['rejected'] ?? 0) ?></em></a>
    <a class="<?= $tab === 'statement' ? 'on' : '' ?>" href="pickup.php?tab=statement">Monthly statement</a>
  </div>
  <a class="btn btn-primary btn-sm" href="pickup.php?tab=<?= $tab ?>&new=1"><?= icon('add', 16) ?>Add a point</a>
  <span class="note">Shops can also apply themselves at <a class="link" href="../partner_apply.php" target="_blank">/partner_apply.php</a>.</span>
</div>

<?php if ($showForm): $c = $current ?: ['capacity' => 30, 'accepts_pickup' => 1, 'accepts_dropoff' => 1]; ?>
<section class="panel">
  <h2><?= $current ? 'Edit ' . e($current['name']) : 'Add a pick-up point' ?> <a href="pickup.php?tab=<?= $tab ?>">Close</a></h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $current ? 'save' : 'add' ?>"><input type="hidden" name="id" value="<?= (int)($current['id'] ?? 0) ?>">
    <div class="cols" style="gap:12px">
      <?php foreach (['name' => 'Shop name', 'address' => 'Street address', 'city' => 'City', 'state' => 'State (2 letters)', 'zip' => 'ZIP', 'hours' => 'Opening hours', 'contact_name' => 'Contact name', 'contact_phone' => 'Contact phone', 'contact_email' => 'Contact email'] as $k => $l): ?>
        <label class="field" style="margin:0"><span><?= $l ?></span><input class="input" name="<?= $k ?>" maxlength="<?= $fields[$k] ?>" value="<?= e($c[$k] ?? '') ?>" <?= in_array($k, ['name', 'address', 'city', 'state', 'zip'], true) ? 'required' : '' ?>></label>
      <?php endforeach; ?>
      <label class="field" style="margin:0"><span>Partner account (username or email, optional)</span><input class="input" name="partner" value="<?= e($c['partner_name'] ?? '') ?>" placeholder="Gives them the partner portal"></label>
      <label class="field" style="margin:0"><span>Capacity (parcels)</span><input class="input" type="number" name="capacity" min="5" max="500" value="<?= (int)$c['capacity'] ?>"></label>
      <label class="field" style="margin:0"><span>Latitude</span><input class="input" name="lat" value="<?= e($c['lat'] ?? '') ?>" placeholder="auto from address"></label>
      <label class="field" style="margin:0"><span>Longitude</span><input class="input" name="lng" value="<?= e($c['lng'] ?? '') ?>" placeholder="auto from address"></label>
    </div>
    <div class="checks">
      <label><input type="checkbox" name="accepts_pickup" value="1" <?= $c['accepts_pickup'] ? 'checked' : '' ?>>Buyers collect here</label>
      <label><input type="checkbox" name="accepts_dropoff" value="1" <?= $c['accepts_dropoff'] ? 'checked' : '' ?>>Sellers drop off here</label>
      <label><input type="checkbox" name="regeocode" value="1" checked>Re-locate on map if the address changed</label>
    </div>
    <label class="field" style="margin:0"><span>Notes (internal)</span><textarea class="input" name="notes" maxlength="500"><?= e($c['notes'] ?? '') ?></textarea></label>
    <button class="btn btn-primary btn-sm"><?= $current ? 'Save' : 'Add pick-up point' ?></button>
  </form>
</section>
<?php endif; ?>

<?php if ($tab === 'statement'):
    $rows = q("SELECT p.id, p.name, p.city, p.state, u.username, u.id uid,
                 SUM(e.type = 'handover') handovers, SUM(e.type = 'received') received, SUM(e.type = 'dropoff') dropoffs, SUM(e.type = 'returned') returned
               FROM pickup_points p LEFT JOIN users u ON u.id = p.partner_user_id
               JOIN pudo_events e ON e.point_id = p.id AND DATE_FORMAT(e.created_at, '%Y-%m') = ?
               GROUP BY p.id ORDER BY handovers DESC", [$month])->fetchAll();
    $totalDue = array_sum(array_map(function ($r) { return $r['handovers'] * pudo_fee(); }, $rows));
?>
<section class="panel">
  <h2>Statement for <?= e(date('F Y', strtotime($month . '-01'))) ?>
    <span><a href="pickup.php?tab=statement&month=<?= e(date('Y-m', strtotime($month . '-01 -1 month'))) ?>">← Prev</a> · <a href="pickup.php?tab=statement&month=<?= e(date('Y-m', strtotime($month . '-01 +1 month'))) ?>">Next →</a> · <a href="pickup.php?tab=statement&month=<?= e($month) ?>&csv=1">Download CSV</a></span></h2>
  <p class="panel-sub"><?= money(pudo_fee()) ?> per parcel handed to a buyer (set in Fees &amp; shipping). Pay partners outside the app, e.g. by bank transfer, then keep the CSV for your records.</p>
  <?php if (!$rows): ?><p class="note" style="margin:0">No activity this month.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Point</th><th>Partner</th><th class="num">Checked in</th><th class="num">Handed over</th><th class="num">Drop-offs</th><th class="num">Returns</th><th class="num">Due</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><a class="strong" href="pickup.php?tab=active&edit=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><div class="note"><?= e($r['city'] . ', ' . $r['state']) ?></div></td>
        <td><?= $r['uid'] ? '<a href="user.php?id=' . (int)$r['uid'] . '">' . e($r['username']) . '</a>' : '<span class="muted">—</span>' ?></td>
        <td class="num"><?= (int)$r['received'] ?></td><td class="num"><?= (int)$r['handovers'] ?></td><td class="num"><?= (int)$r['dropoffs'] ?></td><td class="num"><?= (int)$r['returned'] ?></td>
        <td class="num"><?= money($r['handovers'] * pudo_fee()) ?></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="6"><strong>Total</strong></td><td class="num"><strong><?= money($totalDue) ?></strong></td></tr>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php else:
    $statuses = ['active' => "'active','paused'", 'pending' => "'pending'", 'rejected' => "'rejected'"][$tab];
    $points = q("SELECT p.*, u.username partner_name,
                   (SELECT COUNT(*) FROM orders o WHERE o.pickup_point_id = p.id AND o.status IN ('paid','label_sent','shipped','at_pickup')) load_now,
                   (SELECT COUNT(*) FROM pudo_events e WHERE e.point_id = p.id AND e.type = 'handover' AND e.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) month_handovers
                 FROM pickup_points p LEFT JOIN users u ON u.id = p.partner_user_id
                 WHERE p.status IN ($statuses) ORDER BY p.state, p.city, p.name")->fetchAll();
?>
<section class="panel">
  <?php if (!$points): ?><p class="note" style="margin:0"><?= $tab === 'pending' ? 'No applications waiting.' : 'Nothing here.' ?></p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Point</th><th>Address</th><th>Partner</th><th>Services</th><th class="num">Load</th><?php if ($tab === 'active'): ?><th class="num">Handovers this month</th><?php else: ?><th>Applied</th><?php endif; ?><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($points as $p): ?>
      <tr>
        <td><a class="strong" href="pickup.php?tab=<?= $tab ?>&edit=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><div class="note"><?= e($p['hours']) ?></div><?php if ($p['notes']): ?><div class="note ell" title="<?= e($p['notes']) ?>"><?= e($p['notes']) ?></div><?php endif; ?></td>
        <td><?= e($p['address']) ?><div class="note"><?= e($p['city'] . ', ' . $p['state'] . ' ' . $p['zip']) ?><?= $p['lat'] === null ? ' · <span class="danger">not on map</span>' : '' ?></div></td>
        <td><?= $p['partner_name'] ? '<a href="user.php?id=' . (int)$p['partner_user_id'] . '">' . e($p['partner_name']) . '</a>' : '<span class="muted">—</span>' ?><div class="note"><?= e($p['contact_name']) ?> <?= e($p['contact_phone']) ?></div></td>
        <td class="muted"><?= implode(', ', array_filter([$p['accepts_pickup'] ? 'Pick-up' : '', $p['accepts_dropoff'] ? 'Drop-off' : ''])) ?></td>
        <td class="num"><?= (int)$p['load_now'] ?> / <?= (int)$p['capacity'] ?></td>
        <?php if ($tab === 'active'): ?><td class="num"><?= (int)$p['month_handovers'] ?></td><?php else: ?><td class="muted"><?= $p['applied_at'] ? e(time_ago($p['applied_at'])) : '' ?></td><?php endif; ?>
        <td><?= status_badge($p['status']) ?></td>
        <td><div class="actions">
          <?php if ($p['status'] === 'pending'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-primary btn-xs" name="action" value="approve" data-confirm="Approve <?= e($p['name']) ?>? It appears at checkout straight away.">Approve</button></form>
            <form method="post" onsubmit="var n=prompt('Reason (sent to the applicant, optional)'); if(n===null) return false; this.note.value=n;"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="note"><button class="btn btn-danger btn-xs" name="action" value="reject">Reject</button></form>
          <?php elseif ($p['status'] === 'active'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-xs" name="action" value="pause">Pause</button></form>
          <?php elseif ($p['status'] === 'paused'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-xs" name="action" value="resume">Resume</button></form>
          <?php endif; ?>
          <a class="btn btn-xs" href="pickup.php?tab=<?= $tab ?>&edit=<?= (int)$p['id'] ?>">Edit</a>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-xs btn-danger" name="action" value="delete" data-confirm="Delete this point? Only possible if no orders ever used it.">Delete</button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php admin_end();
