<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
$favPhotos = q('SELECT (SELECT p.path FROM item_photos p WHERE p.item_id = f.item_id ORDER BY p.sort LIMIT 1) AS photo
                FROM favorites f WHERE f.user_id = ? ORDER BY f.created_at DESC LIMIT 4', [$u['id']])->fetchAll(PDO::FETCH_COLUMN);
$listings = (int)q("SELECT COUNT(*) FROM items WHERE user_id = ? AND status <> 'sold'", [$u['id']])->fetchColumn();
$hasSale = (bool)q("SELECT 1 FROM orders WHERE seller_id = ? AND status = 'completed' LIMIT 1", [$u['id']])->fetchColumn();
$badges = ($listings > 0 ? 1 : 0) + ($hasSale ? 1 : 0);
$needsAction = $u['address_line'] === '' || $u['phone'] === '' || !$u['email_verified_at'];

function mi(string $href, string $ic, string $label, string $val = '', string $extra = ''): string
{
    return '<a href="' . e($href) . '">' . icon($ic, 26) . '<span>' . e($label) . '</span>'
         . ($extra ?: '') . ($val !== '' ? '<span class="mi-val">' . $val . '</span>' : '<span class="push"></span>')
         . '<span class="mi-chev">' . icon('chevron') . '</span></a>';
}

page_start('Profile', ['tab' => 'profile']);
?>
<a class="profile-head" href="member.php?id=<?= (int)$u['id'] ?>">
  <?= avatar($u, 64) ?>
  <div style="flex:1"><div class="p-name"><?= e($u['username']) ?></div><div class="muted">View my listings</div></div>
  <span class="muted"><?= icon('chevron') ?></span>
</a>
<div class="stat-box">
  <div class="spread"><span class="row" style="font-size:18px"><?= icon('shield', 26) ?>Badges you earned</span><span class="muted"><?= $badges ?> of 2</span></div>
  <div class="progress"><span style="width:<?= $badges * 50 ?>%"></span></div>
  <p class="muted small" style="margin:10px 0 0"><?= $listings ? '✓' : '○' ?> List your first item &nbsp; <?= $hasSale ? '✓' : '○' ?> Complete your first sale</p>
</div>

<nav class="menu">
  <?php
  $stack = '<span class="fav-stack">';
  foreach ($favPhotos as $n => $p) $stack .= '<img src="' . e(photo_url($p, 'f' . $n)) . '" alt="">';
  $stack .= '</span>';
  echo mi('favorites.php', 'heart', 'Favorite items', '', $favPhotos ? $stack : '');
  ?>
</nav>
<?php if (is_admin()): ?>
<nav class="menu"><?= mi('admin/', 'shield', 'Admin panel') ?></nav>
<?php endif; ?>
<nav class="menu">
  <?= mi('wallet.php', 'wallet', 'Wallet', money($u['wallet_balance'])) ?>
  <?php if (stripe_enabled()): ?><?= mi('payouts.php', 'wallet', 'Payouts', $u['payouts_enabled'] ? 'Set up' : 'Not set up') ?><?php endif; ?>
  <?= mi('orders.php', 'orders', 'My orders') ?>
  <?= mi('member.php?id=' . (int)$u['id'], 'rocket', 'My listings', (string)$listings) ?>
</nav>
<?php $myPoints = partner_points((int)$u['id']); ?>
<nav class="menu">
  <?= $myPoints ? mi('partner.php', 'store', 'My pick-up point', e(ucfirst($myPoints[0]['status']))) : mi('partner_apply.php', 'store', 'Become a pick-up point') ?>
</nav>
<nav class="menu">
  <?= mi('bundle.php', 'tag', 'Bundle discounts', $u['bundle_enabled'] ? 'On' : 'Off') ?>
  <?= mi('vacation.php', 'umbrella', 'Vacation mode', $u['vacation_mode'] ? 'On' : '') ?>
</nav>
<nav class="menu">
  <?= mi('help.php', 'help', 'Your guide to ' . APP_NAME) ?>
  <?= mi('help.php#protection', 'shield', 'Help Center') ?>
</nav>
<nav class="menu">
  <a href="settings.php"><?= icon('settings', 26) ?><span>Settings</span><span class="push"></span><?php if ($needsAction): ?><span class="pill">Action needed</span><?php endif; ?><span class="mi-chev"><?= icon('chevron') ?></span></a>
  <?= mi('security.php', 'lock', 'Security') ?>
  <form method="post" action="logout.php" style="margin:0"><?= csrf_field() ?>
    <button class="menu-item" style="width:100%;background:none;border:0;cursor:pointer;font-size:18px;color:var(--danger)"><?= icon('logout', 26) ?>Log out</button>
  </form>
</nav>
<div class="footer-links"><a href="help.php">Help</a>•<a href="terms.php">Terms</a>•<a href="privacy.php">Privacy</a>•<a href="prohibited.php">Prohibited items</a></div>
<?php page_end();
