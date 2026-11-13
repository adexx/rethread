<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$conv = null;
if (!empty($_GET['id'])) {
    $conv = q('SELECT * FROM conversations WHERE id = ? AND (buyer_id = ? OR seller_id = ?)', [(int)$_GET['id'], $u['id'], $u['id']])->fetch();
    if (!$conv) redirect('inbox.php');
    $itemId = (int)$conv['item_id'];
} else {
    $itemId = (int)($_GET['item'] ?? $_POST['item'] ?? 0);
}
$item = q(item_select() . ' WHERE i.id = ?', [$itemId])->fetch();
if (!$item) redirect('inbox.php');
if (!$conv) {
    if ((int)$item['user_id'] === (int)$u['id']) redirect('item.php?id=' . $itemId);
    $conv = q('SELECT * FROM conversations WHERE item_id = ? AND buyer_id = ?', [$itemId, $u['id']])->fetch() ?: null;
}
$isSeller = (int)$item['user_id'] === (int)$u['id'];
$otherId = $conv ? (int)($isSeller ? $conv['buyer_id'] : $conv['seller_id']) : (int)$item['user_id'];
$other = q('SELECT * FROM users WHERE id = ?', [$otherId])->fetch();
$blocked = is_blocked_between((int)$u['id'], $otherId);
$iBlocked = $blocked && has_blocked((int)$u['id'], $otherId);

if (is_post()) {
    check_csrf();
    if (empty($u['email_verified_at'])) { flash('Confirm your email before sending messages.', 'err'); redirect('verify.php'); }
    if (rate_limited('message', (string)$u['id'], 30, 10)) { flash("You're sending messages too fast. Please wait a few minutes.", 'err'); redirect($_SERVER['REQUEST_URI'] ?? 'inbox.php'); }
    record_attempt('message', (string)$u['id'], false);
    if ($blocked) { flash($iBlocked ? 'You blocked this member. Unblock them to send messages.' : "You can't message this member.", 'err'); redirect($conv ? 'conversation.php?id=' . $conv['id'] : 'item.php?id=' . $itemId); }
    $act = $_POST['action'] ?? 'send';
    $ensureConv = function () use (&$conv, $item, $u) {
        if (!$conv) {
            q('INSERT INTO conversations (item_id, buyer_id, seller_id, updated_at) VALUES (?, ?, ?, NOW())', [$item['id'], $u['id'], $item['user_id']]);
            $conv = q('SELECT * FROM conversations WHERE id = ?', [db()->lastInsertId()])->fetch();
        }
        return (int)$conv['id'];
    };
    $error = null;
    if ($act === 'send') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '' && mb_strlen($body) <= 2000) {
            $cid = $ensureConv();
            $risk = message_risk($body);
            add_message($cid, (int)$u['id'], $body, null, null, $risk ? 1 : 0);
            queue_push($otherId, $u['username'], mb_strimwidth($body, 0, 140, '…'), 'conversation.php?id=' . $cid);
            if ($risk) flash('Heads up: for your safety, keep chats and payments on ' . APP_NAME . '. Payments made elsewhere aren\'t covered by Buyer Protection.', 'err');
        }
    } elseif ($act === 'offer') {
        $amt = round((float)($_POST['amount'] ?? 0), 2);
        if ($item['status'] !== 'active') $error = 'This item is no longer available.';
        elseif ($amt < 1 || $amt >= (float)$item['price']) $error = 'Offer must be at least $1.00 and below the price of ' . money($item['price']) . '.';
        else {
            $cid = $ensureConv();
            q("UPDATE messages SET offer_status = 'declined' WHERE conversation_id = ? AND offer_status = 'pending'", [$cid]);
            add_message($cid, (int)$u['id'], 'Offer: ' . money($amt), $amt, 'pending');
            notify($otherId, $u['username'] . ' sent an offer of ' . money($amt) . ' for "' . $item['title'] . '".', 'conversation.php?id=' . $cid);
        }
    } elseif (in_array($act, ['accept', 'decline'], true) && $conv) {
        $m = q("SELECT * FROM messages WHERE id = ? AND conversation_id = ? AND offer_status = 'pending' AND sender_id <> ?", [(int)($_POST['msg'] ?? 0), $conv['id'], $u['id']])->fetch();
        if ($m) {
            q('UPDATE messages SET offer_status = ? WHERE id = ?', [$act === 'accept' ? 'accepted' : 'declined', $m['id']]);
            $txt = $act === 'accept' ? 'Offer accepted! You can now buy it for ' . money($m['offer_amount']) . '.' : 'Offer of ' . money($m['offer_amount']) . ' declined.';
            add_message((int)$conv['id'], (int)$u['id'], $txt);
            notify((int)$m['sender_id'], $u['username'] . ($act === 'accept' ? ' accepted' : ' declined') . ' your offer for "' . $item['title'] . '".', 'conversation.php?id=' . $conv['id']);
        }
    }
    if ($conv) q('UPDATE conversations SET updated_at = NOW() WHERE id = ?', [$conv['id']]);
    if ($error) flash($error, 'err');
    redirect($conv ? 'conversation.php?id=' . $conv['id'] : 'conversation.php?item=' . $itemId);
}

$messages = [];
if ($conv) {
    q('UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_id <> ? AND read_at IS NULL', [$conv['id'], $u['id']]);
    $messages = q('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id', [$conv['id']])->fetchAll();
}
$buyerId = $conv ? (int)$conv['buyer_id'] : (int)$u['id'];
$price = effective_price($item, $buyerId);
$showOffer = !$isSeller && isset($_GET['offer']) && $item['status'] === 'active';

ob_start(); ?>
<details class="more-menu">
  <summary class="iconbtn" aria-label="More actions"><?= icon('dots') ?></summary>
  <div class="mm-list">
    <a href="member.php?id=<?= $otherId ?>">View profile</a>
    <a href="report.php?type=user&id=<?= $otherId ?>">Report <?= e($other['username']) ?></a>
    <form method="post" action="block.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $otherId ?>"><input type="hidden" name="next" value="<?= e($conv ? 'conversation.php?id=' . $conv['id'] : 'inbox.php') ?>">
      <?php if ($iBlocked): ?><button name="action" value="unblock">Unblock</button>
      <?php else: ?><button class="danger" name="action" value="block" data-confirm="Block <?= e($other['username']) ?>? They won't be able to message you or buy from you.">Block</button><?php endif; ?>
    </form>
  </div>
</details>
<?php $menu = (string)ob_get_clean();
page_start($other['username'], ['back' => $conv ? 'inbox.php' : 'item.php?id=' . $itemId, 'nav' => false, 'right' => $menu]);
?>
<div class="chat-item">
  <img src="<?= e(photo_url($item['photo'], (string)$item['id'])) ?>" alt="">
  <div style="flex:1;min-width:0">
    <a href="item.php?id=<?= (int)$item['id'] ?>" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($item['title']) ?></a>
    <div class="muted small"><?= money($price) ?><?= $price !== (float)$item['price'] ? ' (offer accepted)' : '' ?> · <?= money(price_incl($price)) ?> incl.</div>
  </div>
  <?php if (!$isSeller && $item['status'] === 'active'): ?>
    <a class="btn btn-primary btn-sm" href="checkout.php?items=<?= (int)$item['id'] ?>">Buy now</a>
  <?php elseif ($item['status'] !== 'active'): ?>
    <span class="muted small"><?= e(ucfirst($item['status'])) ?></span>
  <?php endif; ?>
</div>

<div class="chat">
  <?php if (!$messages): ?><p class="muted" style="text-align:center">Ask <?= e($other['username']) ?> a question or make an offer.</p><?php endif; ?>
  <?php foreach ($messages as $m): $mine = (int)$m['sender_id'] === (int)$u['id']; ?>
    <?php if ($m['offer_amount'] !== null): ?>
      <div class="bubble offer">
        <div class="muted small"><?= $mine ? 'You offered' : e($other['username']) . ' offered' ?></div>
        <div class="o-amt"><?= money($m['offer_amount']) ?> <s class="muted small" style="font-weight:400"><?= money($item['price']) ?></s></div>
        <div class="small <?= $m['offer_status'] === 'accepted' ? 'accent' : 'muted' ?>"><?= e(ucfirst((string)$m['offer_status'])) ?> · <?= e(time_ago($m['created_at'])) ?></div>
        <?php if (!$mine && $m['offer_status'] === 'pending'): ?>
          <form method="post" class="btn-row" style="margin-top:12px">
            <?= csrf_field() ?><input type="hidden" name="msg" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-outline btn-sm" style="width:100%" name="action" value="decline">Decline</button>
            <button class="btn btn-primary btn-sm" style="width:100%" name="action" value="accept">Accept</button>
          </form>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="bubble<?= $mine ? ' me' : '' ?>"><?= e($m['body']) ?><span class="b-time"><?= e(time_ago($m['created_at'])) ?></span></div>
      <?php if (!$mine && $m['flagged'] && $risk = message_risk($m['body'])): ?>
        <div class="warn" role="note"><strong>Be careful.</strong> This message mentions <?= e(implode(', ', $risk)) ?>. Scammers often ask to chat or pay outside <?= e(APP_NAME) ?>. Only pay through the Buy button: payments made anywhere else aren't covered by Buyer Protection.
          <a href="report.php?type=message&id=<?= (int)$m['id'] ?>">Report this message</a></div>
      <?php endif; ?>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<?php if ($blocked): ?>
  <p class="pad muted" style="text-align:center"><?= $iBlocked ? 'You blocked ' . e($other['username']) . '. Unblock them from the ⋯ menu to chat again.' : "You can't reply to this conversation." ?></p>
<?php else: ?>
<?php if (!$isSeller && $item['status'] === 'active'): ?>
  <details class="pad" style="padding-top:0" <?= $showOffer ? 'open' : '' ?>>
    <summary class="btn btn-outline">Make an offer</summary>
    <form method="post" class="mt-16">
      <?= csrf_field() ?><input type="hidden" name="action" value="offer"><input type="hidden" name="item" value="<?= (int)$item['id'] ?>">
      <label class="field"><span>Your offer (listed at <?= money($item['price']) ?>)</span>
        <input class="input" type="number" name="amount" step="0.01" min="1" max="<?= e(number_format((float)$item['price'] - 0.01, 2, '.', '')) ?>" placeholder="$0.00" required <?= $showOffer ? 'autofocus' : '' ?>>
      </label>
      <div class="row gap-8" style="margin-bottom:12px">
        <?php foreach ([0.9, 0.8, 0.7] as $r): $amt = floor($item['price'] * $r * 100) / 100; if ($amt < 1) continue; ?>
          <button type="button" class="chip" onclick="this.form.amount.value='<?= number_format($amt, 2, '.', '') ?>'"><?= money($amt) ?></button>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary">Send offer</button>
    </form>
  </details>
<?php endif; ?>

<form method="post" class="composer">
  <?= csrf_field() ?><input type="hidden" name="action" value="send"><input type="hidden" name="item" value="<?= (int)$item['id'] ?>">
  <input name="body" placeholder="Write a message..." maxlength="2000" autocomplete="off" required aria-label="Message">
  <button aria-label="Send"><?= icon('send', 20) ?></button>
</form>
<?php endif; ?>
<?php page_end();
