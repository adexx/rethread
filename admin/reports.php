<?php
require __DIR__ . '/_inc.php';
$admin = require_admin();

if (is_post()) {
    check_csrf();
    $rid = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
    $r = q('SELECT * FROM reports WHERE id = ?', [$rid])->fetch();
    if ($r) {
        $type = $r['target_type'];
        $tid = (int)$r['target_id'];
        $done = '';
        if ($act === 'remove_item' && $type === 'item') {
            $it = q('SELECT id, user_id, title, status FROM items WHERE id = ?', [$tid])->fetch();
            if ($it && in_array($it['status'], ['active', 'reserved', 'hidden'], true)) {
                q("UPDATE items SET status = 'removed' WHERE id = ?", [$tid]);
                notify((int)$it['user_id'], 'Your listing "' . $it['title'] . '" was removed: ' . $r['reason'] . '.', 'item.php?id=' . $tid);
                admin_log('remove_listing', 'item', $tid, 'From report: ' . $r['reason'] . ' — ' . $it['title']);
            }
            $done = 'Listing removed';
        } elseif ($act === 'hide_review' && $type === 'review') {
            $rv = q('SELECT * FROM reviews WHERE id = ?', [$tid])->fetch();
            if ($rv) {
                q('UPDATE reviews SET hidden = 1 WHERE id = ?', [$tid]);
                refresh_rating((int)$rv['reviewee_id']);
                admin_log('hide_review', 'review', $tid, 'From report: ' . $r['reason']);
            }
            $done = 'Review hidden';
        }
        if ($done !== '' || $act === 'resolve' || $act === 'dismiss') {
            // Closing a report closes every open report about the same thing.
            $status = $act === 'dismiss' ? 'dismissed' : 'resolved';
            $n = q("UPDATE reports SET status = ?, resolved_by = ?, resolved_note = ?, resolved_at = NOW() WHERE target_type = ? AND target_id = ? AND status = 'open'",
                   [$status, $admin['id'], $done ?: $note, $type, $tid])->rowCount();
            admin_log($status . '_report', $type, $tid, ($done ?: $note) . " ($n report" . ($n === 1 ? '' : 's') . ')');
            flash(($done ?: ucfirst($status)) . '. ' . $n . ' report' . ($n === 1 ? '' : 's') . ' closed.');
        }
    }
    redirect('reports.php?' . http_build_query(array_intersect_key($_GET, array_flip(['view', 'p']))));
}

$view = in_list((string)($_GET['view'] ?? 'open'), ['open', 'resolved', 'dismissed', 'flagged'], 'open');
$counts = q('SELECT status, COUNT(*) FROM reports GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$counts['flagged'] = (int)q('SELECT COUNT(*) FROM messages WHERE flagged = 1 AND created_at > NOW() - INTERVAL 30 DAY')->fetchColumn();

admin_start('Reports', 'reports');
?>
<div class="toolbar"><div class="seg">
  <?php foreach (['open' => 'Open', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed', 'flagged' => 'Flagged messages (30 days)'] as $k => $l): ?>
    <a class="<?= $view === $k ? 'on' : '' ?>" href="reports.php?view=<?= $k ?>"><?= $l ?><em><?= (int)($counts[$k] ?? 0) ?></em></a>
  <?php endforeach; ?>
</div></div>

<?php if ($view === 'flagged'):
    $total = $counts['flagged'];
    [$offset, $pager] = paginate($total, 30);
    $rows = q("SELECT m.*, s.username AS sender, c.buyer_id, c.seller_id, c.item_id,
                      (SELECT COUNT(*) FROM reports r WHERE r.target_type = 'message' AND r.target_id = m.id) AS reports
               FROM messages m JOIN users s ON s.id = m.sender_id JOIN conversations c ON c.id = m.conversation_id
               WHERE m.flagged = 1 AND m.created_at > NOW() - INTERVAL 30 DAY ORDER BY m.id DESC LIMIT 30 OFFSET $offset")->fetchAll(); ?>
  <section class="panel">
    <p class="panel-sub" style="margin-top:0">Messages that mention contact details, links or paying outside <?= e(APP_NAME) ?>. The recipient saw a safety warning. Most are harmless; look for patterns from the same sender.</p>
    <?php if (!$rows): ?><p class="note" style="margin:0">No flagged messages.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Sender</th><th>Message</th><th>Why</th><th class="num">Reports</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $m): ?>
        <tr>
          <td><a href="user.php?id=<?= (int)$m['sender_id'] ?>"><?= e($m['sender']) ?></a></td>
          <td style="max-width:420px;white-space:normal"><?= e(mb_strimwidth($m['body'], 0, 300, '…')) ?><div class="note">Item #<?= (int)$m['item_id'] ?></div></td>
          <td class="muted"><?= e(implode(', ', message_risk($m['body']))) ?></td>
          <td class="num"><?= (int)$m['reports'] ?></td>
          <td class="muted" style="white-space:nowrap"><?= e(time_ago($m['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?= $pager ?>
    <?php endif; ?>
  </section>
<?php else:
    // One row per reported thing, newest report first, with how many members reported it.
    $total = (int)q('SELECT COUNT(DISTINCT target_type, target_id) FROM reports WHERE status = ?', [$view])->fetchColumn();
    [$offset, $pager] = paginate($total, 25);
    $groups = q("SELECT target_type, target_id, COUNT(*) AS n, MAX(id) AS last_id, MAX(created_at) AS last_at
                 FROM reports WHERE status = ? GROUP BY target_type, target_id ORDER BY n DESC, last_at DESC LIMIT 25 OFFSET $offset", [$view])->fetchAll(); ?>
  <?php if (!$groups): ?><section class="panel"><p class="note" style="margin:0"><?= $view === 'open' ? 'No open reports. 🎉' : 'Nothing here.' ?></p></section><?php endif; ?>
  <?php foreach ($groups as $g):
      $type = $g['target_type'];
      $tid = (int)$g['target_id'];
      $target = report_target($type, $tid);
      $reps = q('SELECT r.*, u.username FROM reports r JOIN users u ON u.id = r.reporter_id WHERE r.target_type = ? AND r.target_id = ? AND r.status = ? ORDER BY r.id DESC LIMIT 20', [$type, $tid, $view])->fetchAll();
      $link = $target ? ($type === 'user' ? 'user.php?id=' . $tid : ($type === 'message' ? 'user.php?id=' . $target['owner_id'] : '../' . $target['url'])) : '';
      $first = $reps[0]; ?>
    <section class="panel">
      <div class="spread" style="gap:12px;align-items:flex-start">
        <div style="min-width:0">
          <div class="note"><?= e(ucfirst($type)) ?> · <?= (int)$g['n'] ?> report<?= (int)$g['n'] === 1 ? '' : 's' ?> · last <?= e(time_ago($g['last_at'])) ?></div>
          <h2 style="margin:4px 0 0;font-size:17px;overflow-wrap:anywhere"><?php if ($target && $link): ?><a href="<?= e($link) ?>" target="_blank"><?= e($target['label']) ?></a><?php else: ?><?= e($target['label'] ?? 'Deleted ' . $type) ?><?php endif; ?></h2>
          <?php if ($target && $type !== 'user'): $owner = q('SELECT id, username FROM users WHERE id = ?', [$target['owner_id']])->fetch(); ?>
            <div class="note">By <a href="user.php?id=<?= (int)$owner['id'] ?>"><?= e($owner['username']) ?></a></div>
          <?php endif; ?>
        </div>
        <?php if ($view === 'open'): ?>
        <form method="post" class="inline-form" style="flex:0 0 auto">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$first['id'] ?>">
          <?php if ($type === 'item' && $target): ?><button class="btn btn-sm btn-danger" name="action" value="remove_item" data-confirm="Remove this listing? The seller is told the reason.">Remove listing</button><?php endif; ?>
          <?php if ($type === 'review' && $target): ?><button class="btn btn-sm btn-danger" name="action" value="hide_review" data-confirm="Hide this review from the profile?">Hide review</button><?php endif; ?>
          <?php if (in_array($type, ['user', 'message'], true) && $target): ?><a class="btn btn-sm btn-outline" href="user.php?id=<?= (int)$target['owner_id'] ?>">Open member (ban, message)</a><?php endif; ?>
          <input class="input" name="note" placeholder="Note (optional)" style="width:160px">
          <button class="btn btn-sm btn-outline" name="action" value="resolve">Mark handled</button>
          <button class="btn btn-sm" name="action" value="dismiss">Dismiss</button>
        </form>
        <?php else: ?>
          <span class="note"><?= e(ucfirst($view)) ?><?= $first['resolved_note'] !== '' ? ': ' . e($first['resolved_note']) : '' ?></span>
        <?php endif; ?>
      </div>
      <div class="tbl-wrap" style="margin-top:10px"><table class="tbl">
        <tbody>
        <?php foreach ($reps as $r): ?>
          <tr>
            <td style="white-space:nowrap"><a href="user.php?id=<?= (int)$r['reporter_id'] ?>"><?= e($r['username']) ?></a></td>
            <td><strong><?= e($r['reason']) ?></strong><?= $r['details'] !== '' ? '<div class="note" style="white-space:normal">' . e($r['details']) . '</div>' : '' ?></td>
            <td class="muted" style="white-space:nowrap"><?= e(time_ago($r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </section>
  <?php endforeach; ?>
  <?= $pager ?>
<?php endif; ?>
<?php admin_end();
