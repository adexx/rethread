<?php
require __DIR__ . '/_inc.php';
require_admin();
$total = (int)q('SELECT COUNT(*) FROM admin_log')->fetchColumn();
[$offset, $pager] = paginate($total, 50);
$rows = q("SELECT l.*, u.username FROM admin_log l JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT 50 OFFSET $offset")->fetchAll();
$links = ['order' => 'order.php?id=', 'user' => 'user.php?id=', 'item' => '../item.php?id=', 'pickup' => 'pickup.php?edit='];
admin_start('Activity log', 'log');
?>
<section class="panel">
  <p class="panel-sub" style="margin-top:0">Every admin action is recorded here and can't be edited.</p>
  <?php if (!$rows): ?><p class="note" style="margin:0">No admin actions yet.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="log-row">
        <td><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td>
        <td><?= e($r['username']) ?></td>
        <td><?= e(ucfirst(str_replace('_', ' ', $r['action']))) ?></td>
        <td><?php if ($r['target_id'] && isset($links[$r['target_type']])): ?><a class="link" href="<?= e($links[$r['target_type']] . (int)$r['target_id']) ?>"><?= e($r['target_type']) ?> #<?= (int)$r['target_id'] ?></a><?php else: ?><span class="muted"><?= e($r['target_type']) ?></span><?php endif; ?></td>
        <td style="max-width:480px;overflow-wrap:anywhere"><?= e($r['details']) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= $pager ?>
  <?php endif; ?>
</section>
<?php admin_end();
