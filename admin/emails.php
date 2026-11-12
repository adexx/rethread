<?php
require __DIR__ . '/_inc.php';
require_admin();
$view = (int)($_GET['view'] ?? 0);
if ($view) {
    $m = q('SELECT * FROM emails WHERE id = ?', [$view])->fetch();
    if (!$m) redirect('emails.php');
    if (isset($_GET['raw'])) {
        // Render the email in a sandboxed frame: no scripts, no same-origin access.
        header("Content-Security-Policy: sandbox; default-src 'none'; img-src * data:; style-src 'unsafe-inline'");
        echo $m['body_html'];
        exit;
    }
}
if (is_post()) {
    check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'retry' && MAIL_DRIVER === 'smtp') {
        q("UPDATE emails SET status = 'queued', attempts = 0 WHERE id = ? AND status = 'failed'", [$id]);
        $ok = send_email_row($id);
        flash($ok ? 'Sent.' : 'Still failing; see the error.', $ok ? 'ok' : 'err');
    }
    redirect('emails.php?view=' . $id);
}
$status = in_list((string)($_GET['status'] ?? 'all'), ['all', 'queued', 'sent', 'failed', 'logged'], 'all');
$qText = trim((string)($_GET['q'] ?? ''));
$where = [];
$params = [];
if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
if ($qText !== '') { $where[] = '(to_email LIKE ? OR subject LIKE ?)'; array_push($params, "%$qText%", "%$qText%"); }
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int)q('SELECT COUNT(*) FROM emails' . $w, $params)->fetchColumn();
[$offset, $pager] = paginate($total, 40);
$rows = q("SELECT id, to_email, subject, status, attempts, error, created_at, sent_at FROM emails $w ORDER BY id DESC LIMIT 40 OFFSET $offset", $params)->fetchAll();
$counts = q('SELECT status, COUNT(*) FROM emails GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

admin_start('Email outbox', 'emails');
?>
<p class="panel-sub" style="margin:-8px 0 16px">
  Mode: <strong><?= MAIL_DRIVER === 'smtp' ? 'SMTP (' . e(SMTP_HOST) . ')' : 'log only — emails are stored here, not sent' ?></strong>.
  <?php if (MAIL_DRIVER === 'smtp'): ?>Queued emails are sent by cron.php every minute.<?php else: ?>Set MAIL_DRIVER=smtp to send for real.<?php endif; ?>
</p>
<?php if ($view): ?>
<section class="panel">
  <h2><?= e($m['subject']) ?> <a href="emails.php">Back</a></h2>
  <dl class="kv" style="margin-bottom:14px">
    <dt>To</dt><dd><?= e($m['to_email']) ?></dd>
    <dt>Status</dt><dd><?= e($m['status']) ?><?= $m['error'] ? ' · <span class="danger">' . e($m['error']) . '</span>' : '' ?></dd>
    <dt>Created</dt><dd><?= e($m['created_at']) ?><?= $m['sent_at'] ? ' · sent ' . e($m['sent_at']) : '' ?></dd>
  </dl>
  <?php if ($m['status'] === 'failed' && MAIL_DRIVER === 'smtp'): ?>
    <form method="post" style="margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $view ?>"><button class="btn btn-outline btn-sm" name="action" value="retry">Retry sending</button></form>
  <?php endif; ?>
  <iframe src="emails.php?view=<?= $view ?>&raw=1" sandbox title="Email preview" style="width:100%;height:520px;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
</section>
<?php endif; ?>
<form class="toolbar">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input class="input" name="q" value="<?= e($qText) ?>" placeholder="Recipient or subject" aria-label="Search emails">
  <button class="btn btn-sm btn-outline">Search</button>
</form>
<div class="toolbar"><div class="seg">
  <?php foreach (['all' => 'All', 'queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed', 'logged' => 'Logged only'] as $k => $l): ?>
    <a class="<?= $status === $k ? 'on' : '' ?>" href="emails.php?status=<?= $k ?>"><?= $l ?><em><?= (int)($k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0)) ?></em></a>
  <?php endforeach; ?>
</div></div>
<section class="panel">
  <?php if (!$rows): ?><p class="note" style="margin:0">No emails.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="muted" style="white-space:nowrap"><?= e(date('M j, g:ia', strtotime($r['created_at']))) ?></td><td><?= e($r['to_email']) ?></td>
        <td><a class="strong ell" style="display:block;max-width:420px" href="emails.php?view=<?= (int)$r['id'] ?>"><?= e($r['subject']) ?></a></td>
        <td><span class="badge badge-<?= ['sent' => 'good', 'failed' => 'bad', 'queued' => 'info'][$r['status']] ?? 'muted' ?>"><?= e($r['status']) ?></span></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?= $pager ?>
  <?php endif; ?>
</section>
<?php admin_end();
