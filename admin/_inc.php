<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/app.php';

function require_admin(): array
{
    $u = user();
    if (!$u) redirect('../login.php?next=' . urlencode('admin/'));
    if (($u['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        exit('<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;background:#16191c;color:#eee;padding:24px"><h2>Admins only</h2><p><a style="color:#f2a65a" href="../index.php">Back to the shop</a></p>');
    }
    if (ADMIN_2FA_REQUIRED) {
        if (empty($u['totp_enabled'])) {
            flash('Set up two-step verification to use the admin panel.', 'err');
            redirect('../security.php#twofa');
        }
        if (empty($_SESSION['2fa_ok'])) {
            // Signed in before 2FA was turned on: ask for a fresh login with a code.
            unset($_SESSION['uid']);
            flash('Please log in again with your two-step code.', 'err');
            redirect('../login.php?next=' . urlencode('admin/'));
        }
    }
    return $u;
}

function admin_log(string $action, string $type = '', ?int $id = null, string $details = ''): void
{
    q('INSERT INTO admin_log (admin_id, action, target_type, target_id, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
      [user()['id'], $action, $type, $id, mb_substr($details, 0, 500)]);
}

function admin_start(string $title, string $active): void
{
    $nav = [
        'dashboard' => ['index.php', 'grid', 'Dashboard'],
        'orders' => ['orders.php', 'orders', 'Orders'],
        'users' => ['users.php', 'user', 'Members'],
        'items' => ['items.php', 'tag', 'Listings'],
        'reports' => ['reports.php', 'info', 'Reports'],
        'categories' => ['categories.php', 'dots9', 'Categories'],
        'pickup' => ['pickup.php', 'store', 'Pick-up points'],
        'settings' => ['settings.php', 'settings', 'Fees & shipping'],
        'messages' => ['messages.php', 'mail', 'Messages'],
        'emails' => ['emails.php', 'send', 'Email outbox'],
        'system' => ['system.php', 'shield', 'Go-live checklist'],
        'log' => ['log.php', 'clock', 'Activity log'],
    ];
    $disputes = (int)q("SELECT COUNT(*) FROM orders WHERE status = 'suspended'")->fetchColumn();
    $openReports = (int)q("SELECT COUNT(DISTINCT target_type, target_id) FROM reports WHERE status = 'open'")->fetchColumn();
    $f = flash();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?> Admin</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23f2a65a'/><text x='16' y='23' font-size='19' font-family='Arial' font-weight='700' text-anchor='middle' fill='%2316191c'>r</text></svg>">
<link rel="stylesheet" href="../assets/style.css?v=5">
<link rel="stylesheet" href="../assets/admin.css?v=1">
</head>
<body class="admin">
<div class="adm">
  <aside class="adm-side">
    <a class="adm-brand" href="index.php"><b>r</b><span><?= e(APP_NAME) ?> <small>Admin</small></span></a>
    <nav>
      <?php foreach ($nav as $k => [$href, $ic, $label]): ?>
        <a href="<?= $href ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= icon($ic, 20) ?><span><?= $label ?></span>
          <?php if ($k === 'orders' && $disputes): ?><em class="count" title="<?= $disputes ?> disputed"><?= $disputes ?></em><?php endif; ?>
          <?php if ($k === 'reports' && $openReports): ?><em class="count" title="<?= $openReports ?> open"><?= $openReports ?></em><?php endif; ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="adm-side-foot">
      <a href="../index.php"><?= icon('back', 18) ?><span>View shop</span></a>
      <form method="post" action="../logout.php"><?= csrf_field() ?><button><?= icon('logout', 18) ?><span>Log out <?= e(user()['username']) ?></span></button></form>
    </div>
  </aside>
  <div class="adm-main">
    <header class="adm-head"><h1><?= e($title) ?></h1></header>
    <?php if ($f): ?><div class="flash flash-<?= e($f['type']) ?>" role="status" style="margin:0 0 16px"><?= e($f['msg']) ?></div><?php endif; ?>
<?php
}

function admin_end(): void
{
    echo "  </div>\n</div>\n<script src=\"../assets/app.js?v=5\"></script>\n</body>\n</html>";
}

function status_badge(string $status): string
{
    $tone = [
        'pending_payment' => 'muted', 'delivered' => 'info', 'at_pickup' => 'info', 'pending' => 'info', 'paused' => 'muted', 'rejected' => 'bad', 'paid' => 'info', 'label_sent' => 'info', 'shipped' => 'info', 'completed' => 'good', 'canceled' => 'muted', 'suspended' => 'bad',
        'active' => 'good', 'reserved' => 'info', 'sold' => 'muted', 'hidden' => 'muted', 'removed' => 'bad',
        'banned' => 'bad', 'admin' => 'info', 'user' => 'muted', 'vacation' => 'info',
    ][$status] ?? 'muted';
    $label = ['label_sent' => 'Label sent', 'suspended' => 'Disputed', 'pending_payment' => 'Awaiting payment', 'at_pickup' => 'At pick-up'][$status] ?? ucfirst($status);
    return '<span class="badge badge-' . $tone . '">' . e($label) . '</span>';
}

/** Simple pager. Returns [offset, html]. */
function paginate(int $total, int $per = 25): array
{
    $page = max(1, (int)($_GET['p'] ?? 1));
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $html = '';
    if ($pages > 1) {
        $link = function (int $p) { $q = $_GET; $q['p'] = $p; return '?' . http_build_query($q); };
        $html = '<nav class="adm-pager">';
        $html .= $page > 1 ? '<a href="' . e($link($page - 1)) . '">← Prev</a>' : '<span></span>';
        $html .= '<span class="muted">Page ' . $page . ' of ' . $pages . ' · ' . $total . ' total</span>';
        $html .= $page < $pages ? '<a href="' . e($link($page + 1)) . '">Next →</a>' : '<span></span>';
        $html .= '</nav>';
    }
    return [($page - 1) * $per, $html];
}

function in_list(string $v, array $allowed, string $default): string
{
    return in_array($v, $allowed, true) ? $v : $default;
}
