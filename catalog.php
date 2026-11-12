<?php
require __DIR__ . '/inc/app.php';
$cats = categories();
$catId = (int)($_GET['cat'] ?? 0);
if ($catId && !isset($cats[$catId])) $catId = 0;
$qText = trim((string)($_GET['q'] ?? ''));
$sizes = array_filter((array)($_GET['size'] ?? []), 'is_string');
$brands = array_filter((array)($_GET['brand'] ?? []), 'is_string');
$conds = array_filter((array)($_GET['cond'] ?? []), 'is_string');
$colors = array_filter((array)($_GET['color'] ?? []), 'is_string');
$min = ($_GET['min'] ?? '') !== '' ? (float)$_GET['min'] : null;
$max = ($_GET['max'] ?? '') !== '' ? (float)$_GET['max'] : null;
$sort = (string)($_GET['sort'] ?? 'relevance');
$page = min(100, max(1, (int)($_GET['page'] ?? 1)));
$perPage = 24;

$where = ["i.status = 'active'", 'u.vacation_mode = 0', 'u.banned = 0'];
$params = [];
if ($catId) {
    $ids = category_descendants($catId);
    $where[] = 'i.category_id IN (' . implode(',', array_map('intval', $ids)) . ')';
}
$members = [];
if ($qText !== '') {
    $ft = fulltext_query($qText);
    if ($ft !== '') {
        // Uses the ft_search index instead of scanning every listing.
        $where[] = 'MATCH(i.title, i.brand, i.description) AGAINST (? IN BOOLEAN MODE)';
        $params[] = $ft;
    } else {
        // Only short words or stopwords (e.g. "XS"): fall back to a title/brand match.
        $where[] = '(i.title LIKE ? OR i.brand LIKE ?)';
        $like = '%' . like_escape($qText) . '%';
        array_push($params, $like, $like);
    }
    // Members whose username starts with the search text (uses the username index).
    if ($page === 1 && preg_match('/^\S+$/u', $qText)) {
        $members = q("SELECT id, username, avatar_color FROM users WHERE username LIKE ? AND banned = 0 AND deleted_at IS NULL ORDER BY username LIMIT 6",
            [like_escape(ltrim($qText, '@')) . '%'])->fetchAll();
    }
}
$in = function (string $col, array $vals) use (&$where, &$params) {
    if (!$vals) return;
    $where[] = $col . ' IN (' . implode(',', array_fill(0, count($vals), '?')) . ')';
    foreach ($vals as $v) $params[] = $v;
};
$in('i.size', $sizes);
$in('i.brand', $brands);
$in('i.condition_label', $conds);
if ($colors) {
    $or = [];
    foreach ($colors as $c) { $or[] = 'FIND_IN_SET(?, i.colors)'; $params[] = $c; }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
if ($min !== null) { $where[] = 'i.price >= ?'; $params[] = $min; }
if ($max !== null) { $where[] = 'i.price <= ?'; $params[] = $max; }

// Every sort ends with i.id so pages never overlap or skip items with equal values.
$order = [
    'relevance' => 'i.sort_at DESC, i.id DESC',
    'newest' => 'i.created_at DESC, i.id DESC',
    'price_asc' => 'i.price ASC, i.id ASC',
    'price_desc' => 'i.price DESC, i.id DESC',
][$sort] ?? 'i.sort_at DESC, i.id DESC';

$whereSql = ' WHERE ' . implode(' AND ', $where);
// The page only shows "500+" above 500, so stop counting there.
$total = (int)q('SELECT COUNT(*) FROM (SELECT 1 FROM items i STRAIGHT_JOIN users u ON u.id = i.user_id' . $whereSql . ' LIMIT 501) t', $params)->fetchColumn();
// One page at a time; ask for one extra row to know whether there is a next page.
$items = item_list($whereSql, $params, $order, $perPage + 1, ($page - 1) * $perPage);
$hasMore = count($items) > $perPage && $page < 100;
$items = array_slice($items, 0, $perPage);

// Facet values within the current category (ignoring other filters) for the filter sheet.
// They change slowly, so they are cached for 10 minutes instead of scanning the category on every visit.
$facets = cache_remember('facets:' . $catId, 600, function () use ($catId) {
    $facetWhere = ["i.status = 'active'"];
    if ($catId) $facetWhere[] = 'i.category_id IN (' . implode(',', array_map('intval', category_descendants($catId))) . ')';
    $fw = ' WHERE ' . implode(' AND ', $facetWhere);
    return [
        'sizes' => q("SELECT DISTINCT i.size FROM items i $fw AND i.size <> '' ORDER BY i.size LIMIT 200")->fetchAll(PDO::FETCH_COLUMN),
        'brands' => q("SELECT DISTINCT i.brand FROM items i $fw AND i.brand <> '' ORDER BY i.brand LIMIT 300")->fetchAll(PDO::FETCH_COLUMN),
    ];
});
$allSizes = $facets['sizes'];
$allBrands = $facets['brands'];

$favs = user_favorites();
$title = $catId ? $cats[$catId]['name'] : 'All items';
$placeholder = $catId ? 'Search in "' . $title . '"' : 'Search for items';
$back = $catId ? ((int)$cats[$catId]['parent_id'] ? 'browse.php?cat=' . (int)$cats[$catId]['parent_id'] : 'browse.php') : 'index.php';

$qs = function (array $override = []) {
    $p = array_merge($_GET, $override);
    foreach ($p as $k => $v) if ($v === null || $v === '' || $v === []) unset($p[$k]);
    return 'catalog.php' . ($p ? '?' . http_build_query($p) : '');
};
$activeCount = count($sizes) + count($brands) + count($conds) + count($colors) + ($min !== null || $max !== null ? 1 : 0);

page_start($title, ['tab' => 'browse', 'header' => false]);
?>
<form class="searchbar" action="catalog.php" role="search">
  <a class="iconbtn" href="<?= e($back) ?>" aria-label="Back"><?= icon('back') ?></a>
  <?php if ($catId): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
  <label class="search"><?= icon('search') ?><input name="q" value="<?= e($qText) ?>" placeholder="<?= e($placeholder) ?>" aria-label="Search"></label>
</form>

<div class="chips">
  <button type="button" class="chip<?= $activeCount ? ' on' : '' ?>" data-open="filters"><?= icon('filter', 18) ?>Filter<?= $activeCount ? ' · ' . $activeCount : '' ?></button>
  <button type="button" class="chip<?= $sizes ? ' on' : '' ?>" data-open="filters">Size</button>
  <button type="button" class="chip<?= $brands ? ' on' : '' ?>" data-open="filters">Brand</button>
  <button type="button" class="chip<?= $conds ? ' on' : '' ?>" data-open="filters">Condition</button>
  <button type="button" class="chip<?= $colors ? ' on' : '' ?>" data-open="filters">Color</button>
  <button type="button" class="chip<?= ($min !== null || $max !== null) ? ' on' : '' ?>" data-open="filters">Price</button>
</div>

<div class="spread" style="padding:4px 16px 14px">
  <span class="muted"><?= $total > 500 ? '500+' : $total ?> result<?= $total === 1 ? '' : 's' ?></span>
  <form action="catalog.php">
    <?php foreach ($_GET as $k => $v): if (in_array($k, ['sort', 'page'], true)) continue; foreach ((array)$v as $vv): ?>
      <input type="hidden" name="<?= e($k) . (is_array($v) ? '[]' : '') ?>" value="<?= e($vv) ?>">
    <?php endforeach; endforeach; ?>
    <select name="sort" class="input" style="min-height:36px;padding:4px 10px;width:auto" data-autosubmit aria-label="Sort">
      <?php foreach (['relevance' => 'Relevance', 'newest' => 'Newest first', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($members): ?>
  <p class="muted small" style="padding:0 16px;margin:0 0 6px">Members</p>
  <div class="chips">
    <?php foreach ($members as $mb): ?><a class="chip" href="member.php?id=<?= (int)$mb['id'] ?>">@<?= e($mb['username']) ?></a><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($items): ?>
  <?php if ($page > 1): ?>
    <div class="pager" data-prev><a class="btn btn-outline" href="<?= e($qs(['page' => $page > 2 ? $page - 1 : null])) ?>">Previous page</a></div>
  <?php endif; ?>
  <div class="grid"><?php foreach ($items as $it) echo item_card($it, $favs); ?></div>
  <?php if ($hasMore): ?>
    <div class="pager"><a class="btn btn-outline" data-more href="<?= e($qs(['page' => $page + 1])) ?>">Show more</a></div>
  <?php endif; ?>
<?php else: ?>
  <?php empty_state('search', 'No results', 'Try removing some filters or searching for something else.', $activeCount || $qText ? 'Clear filters' : '', $catId ? 'catalog.php?cat=' . $catId : 'catalog.php'); ?>
<?php endif; ?>

<dialog class="sheet" id="filters">
  <form action="catalog.php">
    <div class="sheet-head"><strong>Filter</strong><button type="button" class="iconbtn" data-close aria-label="Close"><?= icon('close') ?></button></div>
    <div class="sheet-body">
      <?php if ($catId): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
      <?php if ($qText !== ''): ?><input type="hidden" name="q" value="<?= e($qText) ?>"><?php endif; ?>
      <input type="hidden" name="sort" value="<?= e($sort) ?>">

      <?php if ($allSizes): ?>
      <p class="muted small">Size</p>
      <div class="checks"><?php foreach ($allSizes as $s): ?><label><input type="checkbox" name="size[]" value="<?= e($s) ?>" <?= in_array($s, $sizes, true) ? 'checked' : '' ?>><?= e($s) ?></label><?php endforeach; ?></div>
      <?php endif; ?>

      <?php if ($allBrands): ?>
      <p class="muted small mt-16">Brand</p>
      <div class="checks"><?php foreach ($allBrands as $b): ?><label><input type="checkbox" name="brand[]" value="<?= e($b) ?>" <?= in_array($b, $brands, true) ? 'checked' : '' ?>><?= e($b) ?></label><?php endforeach; ?></div>
      <?php endif; ?>

      <p class="muted small mt-16">Condition</p>
      <div class="checks"><?php foreach (array_keys(CONDITIONS) as $c): ?><label><input type="checkbox" name="cond[]" value="<?= e($c) ?>" <?= in_array($c, $conds, true) ? 'checked' : '' ?>><?= e($c) ?></label><?php endforeach; ?></div>

      <p class="muted small mt-16">Color</p>
      <div class="checks"><?php foreach (COLORS as $c => $hex): ?><label><input type="checkbox" name="color[]" value="<?= e($c) ?>" <?= in_array($c, $colors, true) ? 'checked' : '' ?>><span class="swatch" style="background:<?= e($hex) ?>"></span><?= e($c) ?></label><?php endforeach; ?></div>

      <p class="muted small mt-16">Price</p>
      <div class="price-range">
        <input class="input" type="number" name="min" min="0" step="0.01" placeholder="From $" value="<?= $min !== null ? e($min) : '' ?>" aria-label="Minimum price">
        <input class="input" type="number" name="max" min="0" step="0.01" placeholder="To $" value="<?= $max !== null ? e($max) : '' ?>" aria-label="Maximum price">
      </div>
    </div>
    <div class="sheet-foot">
      <a class="btn" href="<?= e($catId ? 'catalog.php?cat=' . $catId : 'catalog.php') ?>">Clear</a>
      <button class="btn btn-primary">Show results</button>
    </div>
  </form>
</dialog>
<?php page_end();
