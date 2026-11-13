<?php
require __DIR__ . '/inc/app.php';
$id = (int)($_GET['id'] ?? 0);
$item = q(item_select() . ' WHERE i.id = ?', [$id])->fetch();
if (!$item) { http_response_code(404); page_start('Not found', ['back' => true]); empty_state('search', 'This item is no longer available'); page_end(); exit; }

$u = user();
$isOwner = $u && (int)$u['id'] === (int)$item['user_id'];
if (!$isOwner && !is_admin() && in_array($item['status'], ['hidden', 'removed'], true)) redirect('index.php');

// Owner actions
if (is_post() && $isOwner) {
    check_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'bump') { q('UPDATE items SET bumped_at = NOW() WHERE id = ?', [$id]); flash('Item bumped to the top of the feed.'); }
    if ($act === 'hide' && $item['status'] === 'active') { q("UPDATE items SET status = 'hidden' WHERE id = ?", [$id]); flash('Item hidden.'); }
    if ($act === 'unhide' && $item['status'] === 'hidden') { q("UPDATE items SET status = 'active' WHERE id = ?", [$id]); flash('Item visible again.'); }
    if ($act === 'reserve' && $item['status'] === 'active') { q("UPDATE items SET status = 'reserved' WHERE id = ?", [$id]); flash('Marked as reserved.'); }
    if ($act === 'unreserve' && $item['status'] === 'reserved') { q("UPDATE items SET status = 'active' WHERE id = ?", [$id]); flash('Item available again.'); }
    if ($act === 'delete' && in_array($item['status'], ['active', 'hidden', 'reserved'], true)) {
        $inOrder = q('SELECT 1 FROM order_items WHERE item_id = ?', [$id])->fetchColumn();
        if ($inOrder) { q("UPDATE items SET status = 'hidden' WHERE id = ?", [$id]); flash('Item has order history, so it was hidden instead.'); redirect('item.php?id=' . $id); }
        delete_item_photo_files($id);
        q('DELETE FROM items WHERE id = ?', [$id]);
        flash('Listing deleted.');
        redirect('member.php?id=' . $u['id']);
    }
    redirect('item.php?id=' . $id);
}

// Count a view once per visitor session, not on every reload, so busy listings don't
// turn into a stream of writes on one row.
if (!$isOwner) {
    $seen = $_SESSION['viewed'] ?? [];
    if (!isset($seen[$id])) {
        q('UPDATE items SET views = views + 1 WHERE id = ?', [$id]);
        $seen[$id] = 1;
        $_SESSION['viewed'] = array_slice($seen, -200, null, true);   // keep the session small
    }
}
$photos = array_map('media_url', q('SELECT path FROM item_photos WHERE item_id = ? ORDER BY sort, id', [$id])->fetchAll(PDO::FETCH_COLUMN));
if (!$photos) $photos = [photo_url(null, (string)$id)];
$seller = q('SELECT * FROM users WHERE id = ?', [$item['user_id']])->fetch();
$sellerCount = (int)q("SELECT COUNT(*) FROM items WHERE user_id = ? AND status = 'active'", [$seller['id']])->fetchColumn();
$sellerSold = (int)q("SELECT COUNT(*) FROM orders WHERE seller_id = ? AND status = 'completed'", [$seller['id']])->fetchColumn();
$more = item_list(" WHERE i.user_id = ? AND i.status = 'active' AND i.id <> ?", [$seller['id'], $id], 'i.sort_at DESC, i.id DESC', 6);
// Similar items: a random 6 from the 40 most recent in the category. ORDER BY RAND() over the
// whole category would read and sort every listing in it on each item view.
$pool = q("SELECT i.id FROM items i STRAIGHT_JOIN users u ON u.id = i.user_id
           WHERE i.category_id = ? AND i.status = 'active' AND i.id <> ? AND u.vacation_mode = 0 AND u.banned = 0
           ORDER BY i.sort_at DESC LIMIT 40", [$item['category_id'], $id])->fetchAll(PDO::FETCH_COLUMN);
shuffle($pool);
$similar = items_by_ids(array_slice($pool, 0, 6));
$favs = user_favorites();
$isFav = isset($favs[$id]);
$price = effective_price($item, $u ? (int)$u['id'] : null);
$offerAccepted = $price !== (float)$item['price'];
$blockedPair = $u && !$isOwner && is_blocked_between((int)$u['id'], (int)$seller['id']);
$available = $item['status'] === 'active' && !$seller['vacation_mode'] && !$seller['banned'] && !$blockedPair;

// Link previews (WhatsApp, Instagram, iMessage…) and Google product results.
$canonical = app_url('item.php?id=' . $id);
$jsonld = [
    '@context' => 'https://schema.org', '@type' => 'Product', 'name' => $item['title'], 'sku' => (string)$id,
    'image' => array_map('abs_url', array_slice($photos, 0, 4)), 'description' => mb_strimwidth($item['description'], 0, 500, '…'),
    'itemCondition' => strpos($item['condition_label'], 'New') === 0 ? 'https://schema.org/NewCondition' : 'https://schema.org/UsedCondition',
    'offers' => ['@type' => 'Offer', 'price' => number_format((float)$item['price'], 2, '.', ''), 'priceCurrency' => 'USD', 'url' => $canonical,
                 'availability' => $item['status'] === 'active' ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
                 'seller' => ['@type' => 'Person', 'name' => $seller['username']]],
];
if ($item['brand'] !== '') $jsonld['brand'] = ['@type' => 'Brand', 'name' => $item['brand']];
if ((int)$seller['rating_count'] > 0) $jsonld['offers']['seller']['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => number_format((float)$seller['rating_avg'], 1), 'reviewCount' => (int)$seller['rating_count']];
page_start($item['title'], ['header' => false, 'nav' => false, 'noindex' => !in_array($item['status'], ['active', 'reserved', 'sold'], true),
    'meta' => ['description' => money($item['price']) . ' · ' . implode(' · ', array_filter([$item['brand'], $item['size'] !== 'One size' ? 'Size ' . $item['size'] : '', $item['condition_label']])) . '. ' . $item['description'],
               'image' => $photos[0], 'type' => 'product', 'canonical' => $canonical, 'jsonld' => $jsonld]]);
?>
<div class="gallery">
  <div class="gallery-track">
    <?php foreach ($photos as $p): ?><img src="<?= e($p) ?>" alt="<?= e($item['title']) ?>"><?php endforeach; ?>
  </div>
  <div class="gallery-top">
    <a class="iconbtn" href="javascript:history.length>1?history.back():location.assign('index.php')" aria-label="Back"><?= icon('back') ?></a>
    <?php if ($isOwner): ?><a class="iconbtn" href="sell.php?id=<?= $id ?>" aria-label="Edit listing"><?= icon('edit') ?></a><?php endif; ?>
  </div>
  <?php if (count($photos) > 1): ?>
    <div class="gallery-dots"><?php foreach ($photos as $n => $p): ?><span class="<?= $n === 0 ? 'on' : '' ?>"></span><?php endforeach; ?></div>
  <?php endif; ?>
  <form method="post" action="favorite.php" class="fav-form">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <button class="fav<?= $isFav ? ' on' : '' ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" aria-label="Favorite"><?= icon('heart', 22) ?><span><?= (int)$item['favs'] ?></span></button>
  </form>
</div>

<section class="item-head">
  <h2><?= e($item['title']) ?></h2>
  <div class="muted"><?= e(implode(' · ', array_filter([$item['size'] !== 'One size' ? $item['size'] : '', $item['condition_label'], 'Uploaded ' . time_ago($item['created_at'])]))) ?></div>
  <?php if ($offerAccepted): ?>
    <div class="price"><s><?= money($item['price']) ?></s> <span class="accent">Your offer: <?= money($price) ?></span></div>
  <?php else: ?>
    <div class="price"><?= money($price) ?></div>
  <?php endif; ?>
  <div class="price-incl"><?= money(price_incl($price)) ?> incl. Buyer Protection fee <?= icon('shield', 18) ?></div>
  <?php if ($item['status'] !== 'active'): ?><p class="accent"><?= $item['status'] === 'sold' ? 'This item has been sold.' : ($item['status'] === 'reserved' ? 'This item is reserved.' : ($item['status'] === 'removed' ? 'This listing was removed by our moderators.' : 'This item is hidden from other members.')) ?></p><?php endif; ?>
  <?php if ($seller['vacation_mode'] && !$isOwner): ?><p class="accent">This member is on vacation. You can't buy their items right now.</p><?php endif; ?>
</section>
<hr class="hr">

<?php if ($isOwner): ?>
  <section class="pad">
    <p class="muted small" style="margin-top:0"><?= icon('eye', 16) ?> <?= (int)$item['views'] ?> views · <?= (int)$item['favs'] ?> favorites</p>
    <form method="post" class="btn-row">
      <?= csrf_field() ?>
      <a class="btn btn-outline" href="sell.php?id=<?= $id ?>">Edit listing</a>
      <?php if ($item['status'] === 'active'): ?><button class="btn btn-primary" name="action" value="bump"><?= icon('up', 18) ?>Bump</button><?php endif; ?>
      <?php if ($item['status'] === 'active'): ?><button class="btn" name="action" value="reserve">Mark as reserved</button><?php endif; ?>
      <?php if ($item['status'] === 'reserved'): ?><button class="btn" name="action" value="unreserve">Unreserve</button><?php endif; ?>
      <?php if ($item['status'] === 'active'): ?><button class="btn" name="action" value="hide">Hide</button><?php endif; ?>
      <?php if ($item['status'] === 'hidden'): ?><button class="btn" name="action" value="unhide">Show listing</button><?php endif; ?>
      <?php if ($item['status'] !== 'sold'): ?><button class="btn btn-danger" name="action" value="delete" data-confirm="Delete this listing? This can't be undone.">Delete</button><?php endif; ?>
    </form>
  </section>
  <hr class="hr">
<?php endif; ?>

<section>
  <p class="pad muted" style="margin:0;padding-bottom:6px">Description</p>
  <p class="pad" style="margin:0;padding-top:0;white-space:pre-wrap"><?= e($item['description']) ?></p>
  <dl class="dl">
    <?php if ($item['brand']): ?><div><dt>Brand</dt><dd><a class="link" href="catalog.php?brand[]=<?= urlencode($item['brand']) ?>"><?= e($item['brand']) ?></a></dd></div><?php endif; ?>
    <div><dt>Size</dt><dd><?= e($item['size']) ?></dd></div>
    <div><dt>Condition</dt><dd><?= e($item['condition_label']) ?></dd></div>
    <?php if ($item['colors']): ?><div><dt>Color</dt><dd><?php foreach (explode(',', $item['colors']) as $c): ?><span class="swatch" style="background:<?= e(COLORS[$c] ?? '#888') ?>"></span><?= e($c) ?> <?php endforeach; ?></dd></div><?php endif; ?>
    <?php if ($item['material']): ?><div><dt>Material</dt><dd><?= e($item['material']) ?></dd></div><?php endif; ?>
    <div><dt>Category</dt><dd><a class="link" href="catalog.php?cat=<?= (int)$item['category_id'] ?>"><?= e(category_path_label((int)$item['category_id'])) ?></a></dd></div>
    <div><dt>Parcel size</dt><dd><?= e(PARCEL_SIZES[$item['parcel_size']][0]) ?></dd></div>
    <div><dt>Shipping</dt><dd>from <?= money(shipping_cost('pickup', $item['parcel_size'])) ?></dd></div>
    <div><dt>Uploaded</dt><dd><?= e(time_ago($item['created_at'])) ?></dd></div>
  </dl>
</section>

<hr class="hr" style="margin-top:16px">
<a class="seller-card" href="member.php?id=<?= (int)$seller['id'] ?>">
  <?= avatar($seller, 48) ?>
  <div style="flex:1">
    <div style="font-size:18px"><?= e($seller['username']) ?></div>
    <?= stars($seller['rating_avg'] !== null ? (float)$seller['rating_avg'] : null, (int)$seller['rating_count']) ?>
    <div class="muted small"><?= $sellerCount ?> listing<?= $sellerCount === 1 ? '' : 's' ?> · <?= $sellerSold ?> sold<?= $seller['bundle_enabled'] ? ' · Bundle discounts' : '' ?></div>
  </div>
  <span class="muted"><?= icon('chevron') ?></span>
</a>
<?php if (!$isOwner && $seller['bundle_enabled'] && $sellerCount > 1): ?>
  <div class="pad" style="padding-bottom:0">
    <a class="btn btn-outline" href="member.php?id=<?= (int)$seller['id'] ?>&bundle=1">Shop bundles · save up to <?= (int)$seller['bundle_5'] ?>%</a>
  </div>
<?php endif; ?>

<?php if ($more): ?>
  <h2 class="section-title">Member's items</h2>
  <div class="grid"><?php foreach ($more as $it) echo item_card($it, $favs); ?></div>
<?php endif; ?>
<?php if ($similar): ?>
  <h2 class="section-title">Similar items</h2>
  <div class="grid"><?php foreach ($similar as $it) echo item_card($it, $favs); ?></div>
<?php endif; ?>
<?php if (!$isOwner): ?>
  <p class="pad small" style="margin:0"><a class="muted" style="text-decoration:underline" href="report.php?type=item&id=<?= $id ?>">Report this listing</a></p>
<?php endif; ?>
<div style="height:24px"></div>

<?php if (!$isOwner): ?>
<div class="sticky-cta">
  <?php if ($available): ?>
    <div class="btn-row">
      <a class="btn btn-outline" href="conversation.php?item=<?= $id ?>&offer=1">Make an offer</a>
      <a class="btn btn-primary" href="checkout.php?items=<?= $id ?>">Buy now</a>
    </div>
    <a class="btn btn-ghost" href="conversation.php?item=<?= $id ?>"><?= icon('mail', 18) ?>Message seller</a>
  <?php elseif ($blockedPair): ?>
    <p class="muted" style="text-align:center;margin:0">You can't buy from or message this member.</p>
  <?php else: ?>
    <a class="btn" href="conversation.php?item=<?= $id ?>"><?= icon('mail', 18) ?>Message seller</a>
  <?php endif; ?>
  <a class="protect" href="help.php#protection">Buyer Protection covers every purchase</a>
</div>
<?php endif; ?>
<?php page_end();
