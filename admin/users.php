<?php
require __DIR__ . '/_inc.php';
require_admin();

$filter = in_list((string)($_GET['f'] ?? 'all'), ['all', 'sellers', 'banned', 'vacation', 'admins'], 'all');
$qText = trim((string)($_GET['q'] ?? ''));
$sort = in_list((string)($_GET['sort'] ?? 'newest'), ['newest', 'sales', 'listings', 'wallet'], 'newest');
$where = [];
$params = [];
if ($filter === 'banned') $where[] = 'u.banned = 1';
if ($filter === 'vacation') $where[] = 'u.vacation_mode = 1';
if ($filter === 'admins') $where[] = "u.role = 'admin'";
if ($filter === 'sellers') $where[] = 'EXISTS (SELECT 1 FROM items i WHERE i.user_id = u.id)';
if ($qText !== '') {
    $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR u.id = ?)';
    $like = '%' . $qText . '%';
    array_push($params, $like, $like, $like, ctype_digit($qText) ? (int)$qText : 0);
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int)q('SELECT COUNT(*) FROM users u' . $w, $params)->fetchColumn();
[$offset, $pager] = paginate($total, 30);
$order = ['newest' => 'u.created_at DESC', 'sales' => 'sold DESC', 'listings' => 'listings DESC', 'wallet' => 'u.wallet_balance DESC'][$sort];
$users = q("SELECT u.*,
              (SELECT COUNT(*) FROM items i WHERE i.user_id = u.id AND i.status = 'active') listings,
              (SELECT COUNT(*) FROM orders o WHERE o.seller_id = u.id AND o.status = 'completed') sold,
              (SELECT COUNT(*) FROM orders o WHERE o.buyer_id = u.id) bought,
              (SELECT COUNT(*) FROM orders o WHERE (o.buyer_id = u.id OR o.seller_id = u.id) AND o.status = 'suspended') disputes
            FROM users u $w ORDER BY $order LIMIT 30 OFFSET $offset", $params)->fetchAll();

admin_start('Members', 'users');
?>
<form class="toolbar">
  <input type="hidden" name="f" value="<?= e($filter) ?>">
  <input class="input" name="q" value="<?= e($qText) ?>" placeholder="Username, email, name or ID" aria-label="Search members">
  <select class="input" name="sort" data-autosubmit aria-label="Sort">
    <?php foreach (['newest' => 'Newest', 'sales' => 'Most sales', 'listings' => 'Most listings', 'wallet' => 'Highest wallet'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-sm btn-outline">Search</button>
</form>
<div class="toolbar"><div class="seg">
  <?php foreach (['all' => 'All', 'sellers' => 'Sellers', 'banned' => 'Banned', 'vacation' => 'On vacation', 'admins' => 'Admins'] as $k => $l): ?>
    <a class="<?= $filter === $k ? 'on' : '' ?>" href="users.php?f=<?= $k ?>&q=<?= urlencode($qText) ?>&sort=<?= $sort ?>"><?= $l ?></a>
  <?php endforeach; ?>
</div></div>
<section class="panel">
  <?php if (!$users): ?><p class="note" style="margin:0">No members match.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Member</th><th>Name</th><th>Status</th><th class="num">Listings</th><th class="num">Sold</th><th class="num">Bought</th><th class="num">Wallet</th><th>Joined</th></tr></thead>
    <tbody>
    <?php foreach ($users as $m): ?>
      <tr>
        <td><a class="row" href="user.php?id=<?= (int)$m['id'] ?>"><?= avatar($m, 32) ?><span><span class="strong"><?= e($m['username']) ?></span><br><span class="note"><?= e($m['email']) ?></span></span></a></td>
        <td><?= e($m['full_name']) ?></td>
        <td><?= $m['banned'] ? status_badge('banned') : '' ?> <?= $m['role'] === 'admin' ? status_badge('admin') : '' ?> <?= $m['vacation_mode'] ? status_badge('vacation') : '' ?> <?= $m['disputes'] ? '<span class="badge badge-bad">' . (int)$m['disputes'] . ' dispute</span>' : '' ?></td>
        <td class="num"><?= (int)$m['listings'] ?></td>
        <td class="num"><?= (int)$m['sold'] ?></td>
        <td class="num"><?= (int)$m['bought'] ?></td>
        <td class="num"><?= money($m['wallet_balance']) ?></td>
        <td class="muted" style="white-space:nowrap"><?= e(date('M j, Y', strtotime($m['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= $pager ?>
  <?php endif; ?>
</section>
<?php admin_end();
