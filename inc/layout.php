<?php
/**
 * page_start(title, opts): opts = ['tab' => home|browse|sell|inbox|profile|null, 'back' => url|true|null,
 *                                  'right' => html, 'nav' => bool, 'header' => bool, 'class' => string]
 */
function page_start(string $title, array $o = []): void
{
    $o += ['tab' => null, 'back' => null, 'right' => '', 'nav' => true, 'header' => true, 'class' => '', 'close' => false];
    $GLOBALS['__nav'] = $o['nav'];
    $GLOBALS['__tab'] = $o['tab'];
    $f = flash();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#16191c">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23f2a65a'/><text x='16' y='23' font-size='19' font-family='Arial' font-weight='700' text-anchor='middle' fill='%2316191c'>r</text></svg>">
<link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body class="<?= e($o['class']) ?><?= $o['nav'] ? ' has-nav' : '' ?>">
<div class="app">
<?php if ($o['header']): ?>
  <header class="topbar">
    <div class="tb-left">
      <?php if ($o['back'] === true): ?>
        <a class="iconbtn" href="javascript:history.length>1?history.back():location.assign('index.php')" aria-label="Back"><?= icon($o['close'] ? 'close' : 'back') ?></a>
      <?php elseif ($o['back']): ?>
        <a class="iconbtn" href="<?= e($o['back']) ?>" aria-label="Back"><?= icon($o['close'] ? 'close' : 'back') ?></a>
      <?php endif; ?>
    </div>
    <h1 class="tb-title"><?= e($title) ?></h1>
    <div class="tb-right"><?= $o['right'] ?></div>
  </header>
<?php endif; ?>
<?php if ($f): ?><div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div><?php endif; ?>
<main class="main">
<?php
}

function page_end(): void
{
    echo "</main>\n";
    if (!empty($GLOBALS['__nav'])) {
        $tab = $GLOBALS['__tab'];
        $c = unread_counts();
        $tabs = [
            'home' => ['index.php', 'home', 'Home'],
            'browse' => ['browse.php', 'grid', 'Browse'],
            'sell' => ['sell.php', 'plus', 'Sell'],
            'inbox' => ['inbox.php', 'mail', 'Inbox'],
            'profile' => ['profile.php', 'user', 'Profile'],
        ];
        echo '<nav class="tabbar">';
        foreach ($tabs as $k => $t) {
            $dot = ($k === 'inbox' && ($c['messages'] + $c['notifications']) > 0) ? '<span class="dot"></span>' : '';
            echo '<a href="' . $t[0] . '" class="' . ($tab === $k ? 'on' : '') . '"><span class="tbi">' . icon($t[1], 24) . $dot . '</span><span>' . $t[2] . '</span></a>';
        }
        echo '</nav>';
    }
    echo "</div>\n<script src=\"assets/app.js?v=4\"></script>\n</body>\n</html>";
}

function item_card(array $it, array $favs): string
{
    $isFav = isset($favs[(int)$it['id']]);
    $bumped = $it['bumped_at'] && strtotime($it['bumped_at']) > time() - 3 * 86400;
    $meta = array_filter([$it['size'] !== 'One size' ? $it['size'] : '', $it['condition_label']]);
    ob_start(); ?>
  <article class="card">
    <div class="card-media">
    <a class="card-img" href="item.php?id=<?= (int)$it['id'] ?>">
      <img src="<?= e(photo_url($it['photo'], (string)$it['id'])) ?>" alt="<?= e($it['title']) ?>" loading="lazy">
      <?php if ($it['status'] === 'sold'): ?><span class="badge-sold">Sold</span><?php elseif ($it['status'] === 'reserved'): ?><span class="badge-sold">Reserved</span><?php elseif ($it['status'] === 'removed'): ?><span class="badge-sold">Removed</span><?php elseif ($it['status'] === 'hidden'): ?><span class="badge-sold">Hidden</span><?php endif; ?>
    </a>
    <form method="post" action="favorite.php" class="fav-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
      <button class="fav<?= $isFav ? ' on' : '' ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" aria-label="Favorite"><?= icon('heart', 18) ?><span><?= (int)$it['favs'] ?></span></button>
    </form>
    </div>
    <a class="card-body" href="item.php?id=<?= (int)$it['id'] ?>">
      <div class="card-row"><span class="brand"><?= e($it['brand'] ?: $it['title']) ?></span><?php if ($bumped): ?><span class="bumped">Bumped</span><?php endif; ?></div>
      <div class="muted small"><?= e(implode(' · ', $meta)) ?></div>
      <div class="price"><?= money($it['price']) ?></div>
      <div class="price-incl"><?= money(price_incl((float)$it['price'])) ?> incl. <?= icon('shield', 15) ?></div>
    </a>
  </article>
<?php
    return (string)ob_get_clean();
}

function empty_state(string $iconName, string $title, string $text = '', string $cta = '', string $href = ''): void
{
    echo '<div class="empty">' . icon($iconName, 44) . '<h3>' . e($title) . '</h3>';
    if ($text) echo '<p class="muted">' . e($text) . '</p>';
    if ($cta) echo '<a class="btn btn-primary" href="' . e($href) . '">' . e($cta) . '</a>';
    echo '</div>';
}
