<?php
require __DIR__ . '/_inc.php';
require_admin();

if (is_post()) {
    check_csrf();
    $msg = trim((string)($_POST['message'] ?? ''));
    $aud = in_list((string)($_POST['audience'] ?? ''), ['all', 'sellers', 'buyers', 'one'], 'all');
    $link = trim((string)($_POST['link'] ?? ''));
    if ($link !== '' && !preg_match('#^[a-z0-9_\-]+\.php(\?[^\s"<>]*)?$#i', $link)) $link = '';
    if ($msg === '' || mb_strlen($msg) > 220) {
        flash('Write a message of up to 220 characters.', 'err');
    } else {
        if ($aud === 'one') {
            $who = trim((string)($_POST['username'] ?? ''));
            $ids = q('SELECT id FROM users WHERE username = ? OR email = ?', [$who, $who])->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) { flash('No member called "' . $who . '".', 'err'); redirect('messages.php'); }
        } else {
            $sql = [
                'all' => "SELECT id FROM users WHERE banned = 0 AND role = 'user'",
                'sellers' => "SELECT id FROM users u WHERE banned = 0 AND role = 'user' AND EXISTS (SELECT 1 FROM items i WHERE i.user_id = u.id)",
                'buyers' => "SELECT id FROM users u WHERE banned = 0 AND role = 'user' AND EXISTS (SELECT 1 FROM orders o WHERE o.buyer_id = u.id)",
            ][$aud];
            $ids = q($sql)->fetchAll(PDO::FETCH_COLUMN);
        }
        $pdo = db();
        $pdo->beginTransaction();
        foreach ($ids as $uid) notify((int)$uid, APP_NAME . ' support: ' . $msg, $link ?: 'inbox.php?tab=notifications');
        $pdo->commit();
        admin_log('send_message', 'audience', null, $aud . ($aud === 'one' ? ' (' . ($_POST['username'] ?? '') . ')' : '') . ' — ' . count($ids) . ' recipients: ' . $msg);
        flash('Sent to ' . count($ids) . ' member' . (count($ids) === 1 ? '' : 's') . '.');
    }
    redirect('messages.php');
}

$sent = q("SELECT l.*, u.username FROM admin_log l JOIN users u ON u.id = l.admin_id WHERE l.action IN ('send_message', 'message_member', 'message_order') ORDER BY l.id DESC LIMIT 30")->fetchAll();
$audCounts = [
    'all' => (int)q("SELECT COUNT(*) FROM users WHERE banned = 0 AND role = 'user'")->fetchColumn(),
    'sellers' => (int)q("SELECT COUNT(*) FROM users u WHERE banned = 0 AND role = 'user' AND EXISTS (SELECT 1 FROM items i WHERE i.user_id = u.id)")->fetchColumn(),
    'buyers' => (int)q("SELECT COUNT(*) FROM users u WHERE banned = 0 AND role = 'user' AND EXISTS (SELECT 1 FROM orders o WHERE o.buyer_id = u.id)")->fetchColumn(),
];
admin_start('Messages', 'messages');
?>
<div class="cols-2-1">
  <section class="panel">
    <h2>Send a support message</h2>
    <p class="panel-sub">Delivered to members' Inbox → Notifications, from <?= e(APP_NAME) ?> support.</p>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label class="field" style="margin:0"><span>To</span>
        <select class="input" name="audience" onchange="document.getElementById('one').hidden=this.value!=='one'">
          <option value="all">All members (<?= $audCounts['all'] ?>)</option>
          <option value="sellers">Sellers (<?= $audCounts['sellers'] ?>)</option>
          <option value="buyers">Buyers (<?= $audCounts['buyers'] ?>)</option>
          <option value="one">One member…</option>
        </select>
      </label>
      <label class="field" id="one" hidden style="margin:0"><span>Username or email</span><input class="input" name="username"></label>
      <label class="field" style="margin:0"><span>Message (max 220)</span><textarea class="input" name="message" maxlength="220" required placeholder="e.g. Free shipping on small parcels this weekend!"></textarea></label>
      <label class="field" style="margin:0"><span>Link when tapped (optional page in the app)</span><input class="input" name="link" placeholder="e.g. catalog.php?cat=2 or help.php#fees"></label>
      <button class="btn btn-primary btn-sm" data-confirm="Send this message now?">Send</button>
    </form>
  </section>
  <section class="panel">
    <h2>Recently sent</h2>
    <?php if (!$sent): ?><p class="note" style="margin:0">Nothing sent yet.</p><?php endif; ?>
    <?php foreach ($sent as $s): ?>
      <p class="small" style="margin:0 0 10px"><strong><?= e($s['username']) ?></strong> <span class="note">· <?= e(time_ago($s['created_at'])) ?><?= $s['target_id'] ? ' · ' . e($s['target_type']) . ' #' . (int)$s['target_id'] : '' ?></span><br><?= e($s['details']) ?></p>
    <?php endforeach; ?>
  </section>
</div>
<?php admin_end();
