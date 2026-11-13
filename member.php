<?php
require __DIR__ . '/inc/app.php';
$id = (int)($_GET['id'] ?? 0);
$m = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
if (!$m) redirect('index.php');
$u = user();
$isMe = $u && (int)$u['id'] === $id;
$bundleMode = !$isMe && isset($_GET['bundle']) && $m['bundle_enabled'];
$statusSql = $isMe ? "i.status <> 'sold'" : "i.status = 'active'";
$items = item_list(" WHERE i.user_id = ? AND $statusSql", [$id], 'i.sort_at DESC, i.id DESC', 1000);
$sold = (int)q("SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status = 'completed'", [$id])->fetchColumn();
$tab = ($_GET['tab'] ?? '') === 'reviews' ? 'reviews' : 'listings';
$reviews = $tab === 'reviews'
    ? q('SELECT r.*, u.username, u.avatar_color FROM reviews r JOIN users u ON u.id = r.reviewer_id WHERE r.reviewee_id = ? AND r.hidden = 0 ORDER BY r.id DESC LIMIT 100', [$id])->fetchAll()
    : [];
$following = $u && !$isMe ? is_following((int)$u['id'], $id) : false;
$iBlocked = $u && !$isMe ? has_blocked((int)$u['id'], $id) : false;
$favs = user_favorites();

page_start($m['username'], ['back' => true, 'tab' => $isMe ? 'profile' : null, 'noindex' => (bool)$m['deleted_at'] || (bool)$m['banned'],
    'meta' => ['description' => $m['username'] . ' on ' . APP_NAME . ': ' . count($items) . ' item' . (count($items) === 1 ? '' : 's') . ' for sale'
                   . ((int)$m['rating_count'] ? ', rated ' . number_format((float)$m['rating_avg'], 1) . '/5 from ' . (int)$m['rating_count'] . ' review' . ((int)$m['rating_count'] === 1 ? '' : 's') : '') . '.'
                   . ($m['bio'] !== '' ? ' ' . $m['bio'] : ''),
               'image' => $items ? photo_url($items[0]['photo'], (string)$items[0]['id']) : app_url('assets/icons/og-default.png'),
               'type' => 'profile', 'canonical' => app_url('member.php?id=' . $id)]]);
?>
<div class="profile-head">
  <?= avatar($m, 72) ?>
  <div style="flex:1">
    <div class="p-name"><?= e($m['username']) ?></div>
    <a href="member.php?id=<?= $id ?>&tab=reviews"><?= stars($m['rating_avg'] !== null ? (float)$m['rating_avg'] : null, (int)$m['rating_count']) ?></a>
    <div class="muted small"><?= count($items) ?> listing<?= count($items) === 1 ? '' : 's' ?> · <?= $sold ?> sold · <?= (int)$m['follower_count'] ?> follower<?= (int)$m['follower_count'] === 1 ? '' : 's' ?> · Joined <?= e(date('M Y', strtotime($m['created_at']))) ?></div>
    <?php if ($m['vacation_mode']): ?><div class="accent small"><?= icon('umbrella', 15) ?> On vacation</div><?php endif; ?>
  </div>
</div>
<?php if ($m['bio']): ?><p class="pad" style="padding-top:0;margin:0"><?= e($m['bio']) ?></p><?php endif; ?>
<?php if (!$isMe && !$m['deleted_at']): ?>
  <div class="profile-actions">
    <?php if ($iBlocked): ?>
      <span class="muted small" style="flex:1">You blocked this member. They can't message you or buy from you.</span>
    <?php else: ?>
      <form method="post" action="follow.php" style="flex:1;margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
        <button class="btn <?= $following ? 'btn-outline' : 'btn-primary' ?>" name="action" value="<?= $following ? 'unfollow' : 'follow' ?>" style="width:100%"><?= $following ? 'Following' : 'Follow' ?></button>
      </form>
    <?php endif; ?>
    <details class="more-menu">
      <summary class="iconbtn" aria-label="More actions"><?= icon('dots') ?></summary>
      <div class="mm-list">
        <a href="report.php?type=user&id=<?= $id ?>">Report <?= e($m['username']) ?></a>
        <form method="post" action="block.php" style="margin:0"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <?php if ($iBlocked): ?><button name="action" value="unblock">Unblock</button>
          <?php else: ?><button class="danger" name="action" value="block" data-confirm="Block <?= e($m['username']) ?>? They won't be able to message you or buy your items, and you won't be able to buy theirs.">Block</button><?php endif; ?>
        </form>
      </div>
    </details>
  </div>
<?php endif; ?>
<nav class="tabs">
  <a href="member.php?id=<?= $id ?>" class="<?= $tab === 'listings' ? 'on' : '' ?>">Listings</a>
  <a href="member.php?id=<?= $id ?>&tab=reviews" class="<?= $tab === 'reviews' ? 'on' : '' ?>">Reviews <?= (int)$m['rating_count'] ?: '' ?></a>
</nav>
<?php if ($tab === 'reviews'): ?>
  <?php if (!$reviews) empty_state('user', 'No reviews yet', 'Reviews appear here after completed orders.'); ?>
  <?php foreach ($reviews as $r): ?>
    <div class="review">
      <div class="review-head">
        <?= avatar(['username' => $r['username'], 'avatar_color' => $r['avatar_color']], 34) ?>
        <span class="grow"><a href="member.php?id=<?= (int)$r['reviewer_id'] ?>"><?= e($r['username']) ?></a><span class="muted small"> · <?= $r['role'] === 'buyer' ? 'bought from' : 'sold to' ?> <?= e($m['username']) ?> · <?= e(time_ago($r['created_at'])) ?></span></span>
        <?= stars((float)$r['rating'], 1, false) ?>
      </div>
      <?php if ($r['body'] !== ''): ?><p><?= e($r['body']) ?></p><?php endif; ?>
      <?php if ($u && (int)$u['id'] !== (int)$r['reviewer_id']): ?><div class="bubble-tools"><a href="report.php?type=review&id=<?= (int)$r['id'] ?>">Report</a></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php page_end(); exit; endif; ?>
<?php if ($isMe): ?>
  <div class="pad" style="padding-top:0"><a class="btn btn-primary" href="sell.php"><?= icon('add', 20) ?>Sell an item</a></div>
<?php elseif ($m['bundle_enabled'] && count($items) > 1 && !$bundleMode): ?>
  <div class="box"><div class="box-title"><?= icon('tag', 22) ?>Bundle discounts</div>
    <div class="box-line">Save <?= (int)$m['bundle_2'] ?>% on 2 items, <?= (int)$m['bundle_3'] ?>% on 3+, <?= (int)$m['bundle_5'] ?>% on 5+ and pay shipping once.</div>
    <a class="btn btn-outline mt-16" href="member.php?id=<?= $id ?>&bundle=1">Shop bundles</a>
  </div>
<?php endif; ?>

<?php if ($bundleMode): ?>
  <form action="checkout.php" id="bundle-form" onsubmit="this.items.value=[...this.querySelectorAll('input[name=pick]:checked')].map(i=>i.value).join(',');return this.items.value!=='';">
    <input type="hidden" name="items">
    <p class="pad" style="padding-top:0;margin:0">Select the items you want. Discounts: 2 items <?= (int)$m['bundle_2'] ?>% · 3+ <?= (int)$m['bundle_3'] ?>% · 5+ <?= (int)$m['bundle_5'] ?>%.</p>
    <div class="grid">
      <?php foreach ($items as $it): ?>
        <label class="card" style="cursor:pointer">
          <span class="card-img"><img src="<?= e(photo_url($it['photo'], (string)$it['id'])) ?>" alt=""></span>
          <span class="card-body"><span class="row gap-8"><input type="checkbox" name="pick" value="<?= (int)$it['id'] ?>" style="width:20px;height:20px;accent-color:var(--accent)"><?= e($it['title']) ?></span>
          <span class="price"><?= money($it['price']) ?></span></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="sticky-cta" style="margin-top:16px"><button class="btn btn-primary">Buy bundle</button></div>
  </form>
<?php elseif ($items): ?>
  <div class="grid"><?php foreach ($items as $it) echo item_card($it, $favs); ?></div>
<?php else: ?>
  <?php empty_state('tag', $isMe ? 'You have no listings' : 'No items for sale', $isMe ? 'Upload your first item. It only takes a minute.' : '', $isMe ? 'Sell an item' : '', 'sell.php'); ?>
<?php endif; ?>
<?php page_end();
