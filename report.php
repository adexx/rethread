<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$type = (string)($_GET['type'] ?? $_POST['type'] ?? '');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!isset(REPORT_REASONS[$type])) redirect('index.php');
$target = report_target($type, $id);
if (!$target) { flash('That no longer exists.', 'err'); redirect('index.php'); }
// Members can only report messages from conversations they are part of.
if ($type === 'message' && !q('SELECT 1 FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)', [$target['conversation_id'], $u['id'], $u['id']])->fetchColumn()) redirect('inbox.php');
$back = $type === 'message' ? 'conversation.php?id=' . $target['conversation_id'] : ($target['url'] ?: 'index.php');

$error = '';
if (is_post()) {
    check_csrf();
    if (rate_limited('report', (string)$u['id'], 20, 60)) {
        $error = "You've sent a lot of reports. Please wait a while before sending more.";
    } else {
        record_attempt('report', (string)$u['id'], false);
        try {
            $new = report_create((int)$u['id'], $type, $id, (string)($_POST['reason'] ?? ''), (string)($_POST['details'] ?? ''));
            $blockNow = !empty($_POST['block']) && $target['owner_id'] !== (int)$u['id'];
            if ($blockNow) block_user((int)$u['id'], $target['owner_id']);
            flash(($new ? 'Thanks. Our team will review your report.' : 'You already reported this. Our team will review it.') . ($blockNow ? ' The member is blocked.' : ''));
            redirect($back);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}
$titles = ['item' => 'Report listing', 'user' => 'Report member', 'message' => 'Report message', 'review' => 'Report review'];
page_start($titles[$type], ['back' => $back, 'nav' => false]);
?>
<form method="post" class="pad">
  <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="id" value="<?= $id ?>">
  <?php if ($error): ?><div class="flash flash-err" style="margin:0 0 12px"><?= e($error) ?></div><?php endif; ?>
  <p class="muted" style="margin-top:0"><?= e($target['label']) ?></p>
  <fieldset class="radio-list" style="border:0;padding:0;margin:0 0 16px">
    <legend style="margin-bottom:4px">What's wrong?</legend>
    <?php foreach (REPORT_REASONS[$type] as $n => $r): ?>
      <label><input type="radio" name="reason" value="<?= e($r) ?>" required <?= ($_POST['reason'] ?? '') === $r ? 'checked' : '' ?>><?= e($r) ?></label>
    <?php endforeach; ?>
  </fieldset>
  <label class="field"><span>More details (optional)</span><textarea class="input" name="details" maxlength="1000" placeholder="Anything that helps our team check this"><?= e($_POST['details'] ?? '') ?></textarea></label>
  <?php if ($target['owner_id'] !== (int)$u['id'] && $type !== 'item'): ?>
    <label class="row gap-8" style="margin-bottom:16px"><input type="checkbox" name="block" value="1" style="width:20px;height:20px;accent-color:var(--accent)">Also block this member</label>
  <?php endif; ?>
  <button class="btn btn-primary">Send report</button>
  <p class="muted small">Reports are confidential: the member isn't told who reported them. In an emergency, contact local authorities.</p>
</form>
<?php page_end();
