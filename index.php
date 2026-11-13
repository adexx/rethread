<?php
require __DIR__ . '/inc/app.php';
$u = user();
$favs = user_favorites();
$followFeed = $u ? following_feed((int)$u['id'], 4) : [];
$newest = item_list(" WHERE i.status = 'active' AND u.vacation_mode = 0 AND u.banned = 0", [], 'i.sort_at DESC, i.id DESC', 24);

page_start(APP_NAME, ['tab' => 'home', 'header' => false]);
?>
<form class="searchbar" action="catalog.php">
  <label class="search"><?= icon('search') ?><input name="q" placeholder="Search for items or members" aria-label="Search"></label>
</form>
<?php if (!$u): ?>
  <section class="home-hero">
    <h2>Give clothes a second life</h2>
    <p>Buy and sell second-hand fashion, books and more. Buyer protection on every order.</p>
    <a class="btn btn-primary" href="register.php">Sign up</a>
    <a class="btn btn-ghost" style="width:auto" href="login.php">Log in</a>
  </section>
<?php endif; ?>
<div id="install-slot"></div>
<div class="top-cats" style="margin-top:12px">
  <?php foreach (category_children(null) as $c): ?>
    <a href="browse.php?cat=<?= (int)$c['id'] ?>"><?= icon($c['icon'] ?: 'grid', 26) ?><?= e($c['name']) ?></a>
  <?php endforeach; ?>
</div>
<?php if ($followFeed): ?>
  <h2 class="section-title spread">New from members you follow <a class="small accent" href="following.php">See all</a></h2>
  <div class="grid"><?php foreach ($followFeed as $it) echo item_card($it, $favs); ?></div>
<?php endif; ?>
<h2 class="section-title">Newest items</h2>
<?php if ($newest): ?>
  <div class="grid"><?php foreach ($newest as $it) echo item_card($it, $favs); ?></div>
  <div class="pager"><a class="btn btn-outline" href="catalog.php">See all items</a></div>
<?php else: ?>
  <?php empty_state('tag', 'No items yet', 'Be the first to list something.', 'Sell an item', 'sell.php'); ?>
<?php endif; ?>
<div class="footer-links"><a href="help.php">Help</a>•<a href="terms.php">Terms</a>•<a href="privacy.php">Privacy</a>•<a href="prohibited.php">Prohibited items</a></div>
<?php page_end();
