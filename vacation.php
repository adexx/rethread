<?php
require __DIR__ . '/inc/app.php';
$u = require_login();
if (is_post()) {
    check_csrf();
    $on = !empty($_POST['enabled']) ? 1 : 0;
    q('UPDATE users SET vacation_mode = ? WHERE id = ?', [$on, $u['id']]);
    flash($on ? 'Vacation mode is on. Your items are hidden from search.' : 'Welcome back! Your items are visible again.');
    redirect('vacation.php');
}
page_start('Vacation mode', ['back' => 'profile.php', 'tab' => 'profile']);
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="menu"><label class="menu-item" style="cursor:pointer">
    <span style="flex:1">Vacation mode</span>
    <span class="switch"><input type="checkbox" name="enabled" value="1" <?= $u['vacation_mode'] ? 'checked' : '' ?> data-autosubmit><span></span></span>
  </label></div>
  <p class="pad muted" style="margin:0">Going away? Turn on vacation mode to hide your items from search and stop new purchases. Ongoing orders aren't affected.</p>
</form>
<?php page_end();
