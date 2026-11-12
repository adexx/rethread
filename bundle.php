<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
if (is_post()) {
    check_csrf();
    $on = !empty($_POST['enabled']) ? 1 : 0;
    $clamp = function ($v) { return max(0, min(50, (int)$v)); };
    $b2 = $clamp($_POST['bundle_2'] ?? $u['bundle_2']);
    $b3 = max($b2, $clamp($_POST['bundle_3'] ?? $u['bundle_3']));
    $b5 = max($b3, $clamp($_POST['bundle_5'] ?? $u['bundle_5']));
    q('UPDATE users SET bundle_enabled = ?, bundle_2 = ?, bundle_3 = ?, bundle_5 = ? WHERE id = ?', [$on, $b2, $b3, $b5, $u['id']]);
    flash($on ? 'Bundle discounts are on.' : 'Bundle discounts are off.');
    redirect('bundle.php');
}
page_start('Bundle discounts', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="menu"><label class="menu-item" style="cursor:pointer">
    <span style="flex:1">Enable bundle discounts</span>
    <span class="switch"><input type="checkbox" name="enabled" value="1" <?= $u['bundle_enabled'] ? 'checked' : '' ?> data-autosubmit><span></span></span>
  </label></div>
  <p class="pad muted" style="margin:0">Why give a discount? It's a great way to entice people to buy more from you, and you'll sell more items at once. For help, see our <a class="link" href="help.php#bundles">FAQs</a></p>
  <?php if ($u['bundle_enabled']): ?>
    <div class="pad">
      <?php foreach (['bundle_2' => '2 items', 'bundle_3' => '3 items', 'bundle_5' => '5 items or more'] as $k => $l): ?>
        <label class="sell-row" style="margin:0 0 10px"><span><?= $l ?></span>
          <select name="<?= $k ?>"><?php foreach ([0, 5, 10, 15, 20, 25, 30, 40, 50] as $p): ?><option value="<?= $p ?>" <?= (int)$u[$k] === $p ? 'selected' : '' ?>><?= $p ?>%</option><?php endforeach; ?></select>
        </label>
      <?php endforeach; ?>
      <button class="btn btn-primary">Save</button>
    </div>
  <?php endif; ?>
</form>
<?php page_end();
