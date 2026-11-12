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
$favs = user_favorites();

page_start($m['username'], ['back' => true, 'tab' => $isMe ? 'profile' : null]);
?>
<div class="profile-head">
  <?= avatar($m, 72) ?>
  <div style="flex:1">
    <div class="p-name"><?= e($m['username']) ?></div>
    <div class="muted small"><?= count($items) ?> listing<?= count($items) === 1 ? '' : 's' ?> · <?= $sold ?> sold · Joined <?= e(date('M Y', strtotime($m['created_at']))) ?></div>
    <?php if ($m['vacation_mode']): ?><div class="accent small"><?= icon('umbrella', 15) ?> On vacation</div><?php endif; ?>
  </div>
</div>
<?php if ($m['bio']): ?><p class="pad" style="padding-top:0;margin:0"><?= e($m['bio']) ?></p><?php endif; ?>
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
