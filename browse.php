<?php
require __DIR__ . '/inc/app.php';
$catId = (int)($_GET['cat'] ?? 0);
$cats = categories();
if ($catId && !isset($cats[$catId])) redirect('browse.php');
$children = category_children($catId ?: null);
if ($catId && !$children) redirect('catalog.php?cat=' . $catId);

$parent = $catId ? (int)$cats[$catId]['parent_id'] : 0;
$title = $catId ? $cats[$catId]['name'] : 'Browse';
$right = '<a class="iconbtn" href="catalog.php' . ($catId ? '?cat=' . $catId : '') . '" aria-label="Search">' . icon('search') . '</a>';
page_start($title, ['tab' => 'browse', 'back' => $catId ? ($parent ? 'browse.php?cat=' . $parent : 'browse.php') : null, 'right' => $right]);
$showIcons = !$catId || (int)$cats[$catId]['parent_id'] === 0;
?>
<nav class="cat-list">
  <a href="catalog.php<?= $catId ? '?cat=' . $catId : '' ?>">
    <?php if ($showIcons): ?><?= icon('dots9', 26, 'lead') ?><?php endif; ?>All
  </a>
  <?php foreach ($children as $c): $hasKids = (bool)category_children((int)$c['id']); ?>
    <a href="<?= $hasKids ? 'browse.php?cat=' : 'catalog.php?cat=' ?><?= (int)$c['id'] ?>">
      <?php if ($showIcons): ?><?= icon($c['icon'] ?: 'tag', 26, 'lead') ?><?php endif; ?>
      <?= e($c['name']) ?>
      <?php if ($hasKids): ?><span class="mi-chev"><?= icon('chevron') ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
<?php page_end();
