<?php
require __DIR__ . '/_inc.php';
require_admin();

const REMOVE_REASONS = ['Prohibited item', 'Counterfeit or replica', 'Misleading description or photos', 'Spam or duplicate listing', 'Inappropriate content', 'Other policy violation'];

if (is_post()) {
    check_csrf();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
    $act = (string)($_POST['action'] ?? '');
    $reason = in_array($_POST['reason'] ?? '', REMOVE_REASONS, true) ? $_POST['reason'] : 'Policy violation';
    $done = 0;
    foreach ($ids as $iid) {
        $it = q('SELECT id, user_id, title, status FROM items WHERE id = ?', [$iid])->fetch();
        if (!$it) continue;
        if ($act === 'remove' && in_array($it['status'], ['active', 'reserved', 'hidden'], true)) {
            q("UPDATE items SET status = 'removed' WHERE id = ?", [$iid]);
            notify((int)$it['user_id'], 'Your listing "' . $it['title'] . '" was removed: ' . $reason . '.', 'item.php?id=' . $iid);
            admin_log('remove_listing', 'item', $iid, $reason . ' — ' . $it['title']);
            $done++;
        } elseif ($act === 'restore' && $it['status'] === 'removed') {
            q("UPDATE items SET status = 'active' WHERE id = ?", [$iid]);
            notify((int)$it['user_id'], 'Your listing "' . $it['title'] . '" was reviewed and restored.', 'item.php?id=' . $iid);
            admin_log('restore_listing', 'item', $iid, $it['title']);
            $done++;
        } elseif ($act === 'delete' && $it['status'] !== 'sold' && !q('SELECT 1 FROM order_items WHERE item_id = ?', [$iid])->fetchColumn()) {
            delete_item_photo_files($iid);
            q('DELETE FROM items WHERE id = ?', [$iid]);
            admin_log('delete_listing', 'item', $iid, $it['title']);
            $done++;
        }
    }
    flash($done ? "Updated $done listing" . ($done === 1 ? '' : 's') . '.' : 'Nothing changed. Sold items or items with orders can only be removed, not deleted.', $done ? 'ok' : 'err');
    redirect('items.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'q', 'cat', 'p']))));
}

$status = in_list((string)($_GET['status'] ?? 'all'), ['all', 'active', 'reserved', 'sold', 'hidden', 'removed'], 'all');
$qText = trim((string)($_GET['q'] ?? ''));
$cat = (int)($_GET['cat'] ?? 0);
$where = [];
$params = [];
if ($status !== 'all') { $where[] = 'i.status = ?'; $params[] = $status; }
if ($cat && isset(categories()[$cat])) $where[] = 'i.category_id IN (' . implode(',', array_map('intval', category_descendants($cat))) . ')';
if ($qText !== '') {
    $where[] = '(i.id = ? OR i.title LIKE ? OR i.brand LIKE ? OR u.username LIKE ?)';
    $like = '%' . $qText . '%';
    array_push($params, ctype_digit($qText) ? (int)$qText : 0, $like, $like, $like);
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int)q('SELECT COUNT(*) FROM items i JOIN users u ON u.id = i.user_id' . $w, $params)->fetchColumn();
[$offset, $pager] = paginate($total, 30);
$items = q(item_select() . $w . " ORDER BY i.created_at DESC LIMIT 30 OFFSET $offset", $params)->fetchAll();
$counts = q('SELECT status, COUNT(*) FROM items GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$counts['all'] = array_sum($counts);

admin_start('Listings', 'items');
?>
<form class="toolbar">
  <input type="hidden" name="status" value="<?= e($status) ?>">
  <input class="input" name="q" value="<?= e($qText) ?>" placeholder="Title, brand, seller or item #" aria-label="Search listings">
  <select class="input" name="cat" data-autosubmit aria-label="Category">
    <option value="0">All categories</option>
    <?php foreach (category_children(null) as $top): ?>
      <option value="<?= (int)$top['id'] ?>" <?= $cat === (int)$top['id'] ? 'selected' : '' ?>><?= e($top['name']) ?></option>
      <?php foreach (category_children((int)$top['id']) as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>>&nbsp;&nbsp;<?= e($c['name']) ?></option><?php endforeach; ?>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-sm btn-outline">Search</button>
</form>
<div class="toolbar"><div class="seg">
  <?php foreach (['all' => 'All', 'active' => 'Active', 'reserved' => 'Reserved', 'sold' => 'Sold', 'hidden' => 'Hidden by seller', 'removed' => 'Removed'] as $k => $l): ?>
    <a class="<?= $status === $k ? 'on' : '' ?>" href="items.php?status=<?= $k ?>&q=<?= urlencode($qText) ?>&cat=<?= $cat ?>"><?= $l ?><em><?= (int)($counts[$k] ?? 0) ?></em></a>
  <?php endforeach; ?>
</div></div>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <div class="inline-form" style="margin-bottom:12px">
    <select class="input" name="action" style="flex:0 0 auto;width:auto" aria-label="Bulk action">
      <option value="remove">Remove selected</option><option value="restore">Restore selected</option><option value="delete">Delete permanently</option>
    </select>
    <select class="input" name="reason" style="flex:0 1 auto;width:auto" aria-label="Removal reason">
      <?php foreach (REMOVE_REASONS as $r): ?><option><?= e($r) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline btn-sm" data-confirm="Apply this action to the selected listings? Sellers are notified.">Apply</button>
    <span class="note">Removing hides the listing and tells the seller why. Delete is only possible for items never ordered.</span>
  </div>
  <?php if (!$items): ?><p class="note" style="margin:0">No listings match.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th><input type="checkbox" aria-label="Select all" onclick="this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(c=>c.checked=this.checked)" style="accent-color:var(--accent)"></th>
      <th></th><th>Listing</th><th>Seller</th><th>Category</th><th>Status</th><th class="num">Price</th><th class="num">Views</th><th class="num">Favs</th><th>Listed</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= (int)$it['id'] ?>" aria-label="Select <?= e($it['title']) ?>" style="accent-color:var(--accent)"></td>
        <td class="thumbcell"><img src="../<?= e(photo_url($it['photo'], (string)$it['id'])) ?>" alt=""></td>
        <td><a class="strong ell" style="display:block" href="../item.php?id=<?= (int)$it['id'] ?>" target="_blank"><?= e($it['title']) ?></a><div class="note">#<?= (int)$it['id'] ?> · <?= e($it['brand']) ?> · <?= e($it['size']) ?> · <?= e($it['condition_label']) ?></div></td>
        <td><a href="user.php?id=<?= (int)$it['user_id'] ?>"><?= e($it['username']) ?></a></td>
        <td class="muted ell" style="max-width:180px"><?= e(category_path_label((int)$it['category_id'])) ?></td>
        <td><?= status_badge($it['status']) ?></td>
        <td class="num"><?= money($it['price']) ?></td>
        <td class="num"><?= (int)$it['views'] ?></td>
        <td class="num"><?= (int)$it['favs'] ?></td>
        <td class="muted" style="white-space:nowrap"><?= e(date('M j', strtotime($it['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= $pager ?>
  <?php endif; ?>
</form>
<?php admin_end();
