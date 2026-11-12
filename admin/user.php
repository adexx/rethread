<?php
require __DIR__ . '/_inc.php';
$me = require_admin();
$id = (int)($_GET['id'] ?? 0);
$m = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
if (!$m) { flash('Member not found.', 'err'); redirect('users.php'); }
$isSelf = (int)$m['id'] === (int)$me['id'];

if (is_post()) {
    check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($act === 'ban' && !$isSelf && !$m['banned']) {
        if ($reason === '') { flash('Give a reason for the ban.', 'err'); redirect('user.php?id=' . $id); }
        q('UPDATE users SET banned = 1, ban_reason = ? WHERE id = ?', [$reason, $id]);
        admin_log('ban_member', 'user', $id, $reason);
        flash($m['username'] . ' is banned. Their listings are hidden and they are signed out.');
    } elseif ($act === 'unban' && $m['banned']) {
        q("UPDATE users SET banned = 0, ban_reason = '' WHERE id = ?", [$id]);
        notify($id, 'Your account has been reinstated. Welcome back!', 'index.php');
        admin_log('unban_member', 'user', $id, $reason);
        flash($m['username'] . ' is no longer banned.');
    } elseif ($act === 'role' && !$isSelf) {
        $role = $m['role'] === 'admin' ? 'user' : 'admin';
        q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        admin_log($role === 'admin' ? 'grant_admin' : 'revoke_admin', 'user', $id, '');
        flash($role === 'admin' ? $m['username'] . ' is now an admin.' : 'Admin access removed.');
    } elseif ($act === 'wallet') {
        $amt = round((float)($_POST['amount'] ?? 0), 2);
        if ($amt == 0.0 || abs($amt) > 10000 || $reason === '') flash('Enter a non-zero amount (max $10,000) and a reason.', 'err');
        elseif ((float)$m['wallet_balance'] + $amt < 0) flash('That would make the wallet negative.', 'err');
        else {
            q('UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?', [$amt, $id]);
            notify($id, 'Support ' . ($amt > 0 ? 'added ' : 'deducted ') . money(abs($amt)) . ($amt > 0 ? ' to' : ' from') . ' your wallet: ' . $reason, 'wallet.php');
            admin_log('adjust_wallet', 'user', $id, ($amt > 0 ? '+' : '−') . money(abs($amt)) . ': ' . $reason);
            flash('Wallet adjusted.');
        }
    } elseif ($act === 'message') {
        $msg = trim((string)($_POST['message'] ?? ''));
        if ($msg !== '') {
            notify($id, 'Message from ' . APP_NAME . ' support: ' . mb_substr($msg, 0, 220), 'inbox.php?tab=notifications');
            admin_log('message_member', 'user', $id, $msg);
            flash('Message sent.');
        }
    } elseif ($act === 'vacation_off' && $m['vacation_mode']) {
        q('UPDATE users SET vacation_mode = 0 WHERE id = ?', [$id]);
        admin_log('end_vacation', 'user', $id, '');
        flash('Vacation mode turned off.');
    } else {
        flash($isSelf ? "You can't do that to your own account." : "That action isn't available for this member.", 'err');
    }
    redirect('user.php?id=' . $id);
}

$stats = q("SELECT
   (SELECT COUNT(*) FROM items WHERE user_id = :a AND status = 'active') active,
   (SELECT COUNT(*) FROM items WHERE user_id = :b) items,
   (SELECT COUNT(*) FROM orders WHERE seller_id = :c AND status = 'completed') sold,
   (SELECT COALESCE(SUM(subtotal - bundle_discount), 0) FROM orders WHERE seller_id = :d AND status = 'completed') earned,
   (SELECT COUNT(*) FROM orders WHERE buyer_id = :e) bought,
   (SELECT COALESCE(SUM(total), 0) FROM orders WHERE buyer_id = :f AND status <> 'canceled') spent,
   (SELECT COUNT(*) FROM orders WHERE (buyer_id = :g OR seller_id = :h) AND status = 'canceled') canceled,
   (SELECT COUNT(*) FROM orders WHERE (buyer_id = :i OR seller_id = :j) AND status = 'suspended') disputes",
   ['a' => $id, 'b' => $id, 'c' => $id, 'd' => $id, 'e' => $id, 'f' => $id, 'g' => $id, 'h' => $id, 'i' => $id, 'j' => $id])->fetch();
$orders = q("SELECT o.*, IF(o.buyer_id = ?, 'Bought', 'Sold') side,
               (SELECT title FROM order_items WHERE order_id = o.id ORDER BY id LIMIT 1) title
             FROM orders o WHERE o.buyer_id = ? OR o.seller_id = ? ORDER BY o.created_at DESC LIMIT 15", [$id, $id, $id])->fetchAll();
$items = q(item_select() . ' WHERE i.user_id = ? ORDER BY i.created_at DESC LIMIT 15', [$id])->fetchAll();
$history = q("SELECT l.*, u.username FROM admin_log l JOIN users u ON u.id = l.admin_id WHERE l.target_type = 'user' AND l.target_id = ? ORDER BY l.id DESC LIMIT 20", [$id])->fetchAll();

admin_start($m['username'], 'users');
?>
<p style="margin:-8px 0 20px"><a class="link small" href="users.php">← All members</a></p>
<?php if ($m['banned']): ?><div class="flash flash-err" style="margin:0 0 16px">Banned: <?= e($m['ban_reason']) ?></div><?php endif; ?>

<div class="tiles">
  <div class="tile"><div class="t-label">Active listings</div><div class="t-value"><?= (int)$stats['active'] ?></div><div class="t-sub"><?= (int)$stats['items'] ?> total</div></div>
  <div class="tile"><div class="t-label">Sold</div><div class="t-value"><?= (int)$stats['sold'] ?></div><div class="t-sub"><?= money($stats['earned']) ?> earned</div></div>
  <div class="tile"><div class="t-label">Bought</div><div class="t-value"><?= (int)$stats['bought'] ?></div><div class="t-sub"><?= money($stats['spent']) ?> spent</div></div>
  <div class="tile"><div class="t-label">Wallet</div><div class="t-value"><?= money($m['wallet_balance']) ?></div></div>
  <div class="tile<?= $stats['disputes'] ? ' alert' : '' ?>"><div class="t-label">Disputes / cancels</div><div class="t-value"><?= (int)$stats['disputes'] ?> / <?= (int)$stats['canceled'] ?></div></div>
</div>

<div class="cols-2-1">
  <div>
    <section class="panel">
      <h2>Orders <a href="orders.php?q=<?= urlencode($m['username']) ?>">All</a></h2>
      <?php if (!$orders): ?><p class="note" style="margin:0">No orders.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>#</th><th></th><th>Item</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($orders as $o): ?>
          <tr><td><a class="strong" href="order.php?id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a></td><td class="muted"><?= $o['side'] ?></td>
            <td class="ell"><?= e($o['title']) ?></td><td><?= status_badge($o['status']) ?></td><td class="num"><?= money($o['total']) ?></td>
            <td class="muted"><?= e(date('M j', strtotime($o['created_at']))) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
    <section class="panel">
      <h2>Listings <a href="items.php?q=<?= urlencode($m['username']) ?>">All</a></h2>
      <?php if (!$items): ?><p class="note" style="margin:0">No listings.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl"><tbody>
        <?php foreach ($items as $it): ?>
          <tr><td class="thumbcell"><img src="../<?= e(photo_url($it['photo'], (string)$it['id'])) ?>" alt=""></td>
            <td><a class="strong" href="../item.php?id=<?= (int)$it['id'] ?>" target="_blank"><?= e($it['title']) ?></a><div class="note"><?= e($it['brand']) ?> · <?= e(time_ago($it['created_at'])) ?></div></td>
            <td><?= status_badge($it['status']) ?></td><td class="num"><?= money($it['price']) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
  </div>
  <div>
    <section class="panel">
      <h2><span class="row"><?= avatar($m, 40) ?><?= e($m['username']) ?></span></h2>
      <dl class="kv">
        <dt>ID</dt><dd><?= (int)$m['id'] ?></dd>
        <dt>Name</dt><dd><?= e($m['full_name'] ?: '—') ?></dd>
        <dt>Email</dt><dd><?= e($m['email']) ?></dd>
        <dt>Phone</dt><dd><?= e($m['phone'] ?: '—') ?></dd>
        <dt>Address</dt><dd><?= $m['address_line'] ? e($m['address_line'] . ', ' . trim($m['zip'] . ' ' . $m['city'] . ' ' . $m['state'])) : '—' ?></dd>
        <dt>Joined</dt><dd><?= e(date('M j, Y', strtotime($m['created_at']))) ?></dd>
        <dt>Role</dt><dd><?= status_badge($m['role']) ?></dd>
        <dt>Bundles</dt><dd><?= $m['bundle_enabled'] ? (int)$m['bundle_2'] . '/' . (int)$m['bundle_3'] . '/' . (int)$m['bundle_5'] . '%' : 'Off' ?></dd>
        <dt>Vacation</dt><dd><?= $m['vacation_mode'] ? 'On' : 'Off' ?></dd>
      </dl>
      <p class="mt-16" style="margin-bottom:0"><a class="link small" href="../member.php?id=<?= $id ?>" target="_blank">View public profile ↗</a></p>
    </section>

    <section class="panel">
      <h2>Send message</h2>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="message">
        <textarea class="input" name="message" maxlength="220" placeholder="Appears in the member's notifications" required aria-label="Message"></textarea>
        <button class="btn btn-outline btn-sm">Send</button>
      </form>
    </section>

    <section class="panel">
      <h2>Adjust wallet</h2>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="wallet">
        <div class="inline-form">
          <input class="input" type="number" step="0.01" name="amount" placeholder="+5.00 or -5.00" required aria-label="Amount">
          <input class="input" name="reason" maxlength="200" placeholder="Reason" required aria-label="Reason">
        </div>
        <button class="btn btn-outline btn-sm" data-confirm="Change this member's wallet balance?">Apply</button>
      </form>
    </section>

    <section class="panel">
      <h2>Account</h2>
      <?php if ($isSelf): ?><p class="note" style="margin:0">This is your account. Another admin must change your role or ban you.</p><?php else: ?>
      <div class="stack">
        <?php if ($m['banned']): ?>
          <form method="post"><?= csrf_field() ?><button class="btn btn-primary btn-sm" name="action" value="unban">Lift ban</button></form>
        <?php else: ?>
          <form method="post" class="stack"><?= csrf_field() ?>
            <input class="input" name="reason" maxlength="200" placeholder="Reason for ban (shown in the log)" required aria-label="Ban reason">
            <button class="btn btn-danger btn-sm" name="action" value="ban" data-confirm="Ban <?= e($m['username']) ?>? They'll be signed out and their listings hidden.">Ban member</button>
          </form>
        <?php endif; ?>
        <?php if ($m['vacation_mode']): ?><form method="post"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="vacation_off">Turn off vacation mode</button></form><?php endif; ?>
        <form method="post"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="role" data-confirm="<?= $m['role'] === 'admin' ? 'Remove admin access?' : 'Give this member full admin access?' ?>"><?= $m['role'] === 'admin' ? 'Remove admin access' : 'Make admin' ?></button></form>
      </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Admin history</h2>
      <?php if (!$history): ?><p class="note" style="margin:0">None.</p><?php endif; ?>
      <?php foreach ($history as $h): ?>
        <p class="small" style="margin:0 0 8px"><strong><?= e($h['username']) ?></strong> <?= e(str_replace('_', ' ', $h['action'])) ?> <span class="note">· <?= e(time_ago($h['created_at'])) ?></span><br><span class="note"><?= e($h['details']) ?></span></p>
      <?php endforeach; ?>
    </section>
  </div>
</div>
<?php admin_end();
