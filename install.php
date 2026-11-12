<?php
// Creates the database, tables and demo data.
//   Browser: http://localhost/vinted/install.php        (only installs if empty)
//            http://localhost/vinted/install.php?reset=1 (drops and rebuilds everything)
//   CLI:     php install.php [--reset]
declare(strict_types=1);
require __DIR__ . '/config.php';

$cli   = PHP_SAPI === 'cli';

// The installer can wipe data, so it only runs from the command line, or from the same machine on a local setup.
if (!$cli) {
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    if (APP_ENV === 'production' || !$local) {
        http_response_code(403);
        exit('Installer disabled. Run "php install.php" on the server instead.');
    }
}
$reset = $cli ? in_array('--reset', $argv ?? [], true) : isset($_GET['reset']);
$out   = [];
function say(string $s): void { global $out; $out[] = $s; }

date_default_timezone_set(APP_TIMEZONE);
$pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '" . date('P') . "'");
$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `' . DB_NAME . '`');

$tables = ['pudo_events', 'webhook_events', 'emails', 'login_attempts', 'tokens', 'admin_log', 'settings', 'notifications', 'messages', 'conversations', 'order_items', 'orders', 'pickup_points', 'favorites', 'item_photos', 'items', 'categories', 'users'];
if ($reset) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) $pdo->exec("DROP TABLE IF EXISTS `$t`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    say('Dropped existing tables.');
}

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(40) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  address_line VARCHAR(190) NOT NULL DEFAULT '',
  city VARCHAR(80) NOT NULL DEFAULT '',
  state VARCHAR(40) NOT NULL DEFAULT '',
  zip VARCHAR(15) NOT NULL DEFAULT '',
  bio VARCHAR(500) NOT NULL DEFAULT '',
  avatar_color VARCHAR(20) NOT NULL DEFAULT '#5b7c99',
  bundle_enabled TINYINT(1) NOT NULL DEFAULT 0,
  bundle_2 TINYINT UNSIGNED NOT NULL DEFAULT 5,
  bundle_3 TINYINT UNSIGNED NOT NULL DEFAULT 10,
  bundle_5 TINYINT UNSIGNED NOT NULL DEFAULT 15,
  vacation_mode TINYINT(1) NOT NULL DEFAULT 0,
  wallet_balance DECIMAL(10,2) NOT NULL DEFAULT 0,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  banned TINYINT(1) NOT NULL DEFAULT 0,
  ban_reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parent_id INT NULL,
  name VARCHAR(80) NOT NULL,
  icon VARCHAR(20) NULL,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  category_id INT NOT NULL,
  title VARCHAR(120) NOT NULL,
  description TEXT NOT NULL,
  brand VARCHAR(80) NOT NULL DEFAULT '',
  size VARCHAR(30) NOT NULL DEFAULT '',
  condition_label VARCHAR(30) NOT NULL,
  colors VARCHAR(60) NOT NULL DEFAULT '',
  material VARCHAR(40) NOT NULL DEFAULT '',
  price DECIMAL(10,2) NOT NULL,
  parcel_size CHAR(1) NOT NULL DEFAULT 'M',
  status ENUM('active','reserved','sold','hidden','removed') NOT NULL DEFAULT 'active',
  views INT NOT NULL DEFAULT 0,
  bumped_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX (status, created_at),
  INDEX (category_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS item_photos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  path VARCHAR(255) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorites (
  user_id INT NOT NULL,
  item_id INT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, item_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pickup_points (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  address VARCHAR(190) NOT NULL,
  city VARCHAR(80) NOT NULL,
  zip VARCHAR(15) NOT NULL,
  hours VARCHAR(120) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id INT NOT NULL,
  seller_id INT NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  bundle_discount DECIMAL(10,2) NOT NULL DEFAULT 0,
  buyer_fee DECIMAL(10,2) NOT NULL,
  shipping_cost DECIMAL(10,2) NOT NULL,
  tax DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  delivery_type ENUM('pickup','home') NOT NULL,
  carrier VARCHAR(20) NOT NULL,
  parcel_size CHAR(1) NOT NULL,
  pickup_point_id INT NULL,
  ship_name VARCHAR(120) NOT NULL DEFAULT '',
  ship_address VARCHAR(190) NOT NULL DEFAULT '',
  ship_city VARCHAR(80) NOT NULL DEFAULT '',
  ship_state VARCHAR(40) NOT NULL DEFAULT '',
  ship_zip VARCHAR(15) NOT NULL DEFAULT '',
  contact_phone VARCHAR(30) NOT NULL DEFAULT '',
  status ENUM('paid','label_sent','shipped','completed','canceled','suspended') NOT NULL DEFAULT 'paid',
  tracking VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY (buyer_id) REFERENCES users(id),
  FOREIGN KEY (seller_id) REFERENCES users(id),
  FOREIGN KEY (pickup_point_id) REFERENCES pickup_points(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  item_id INT NOT NULL,
  title VARCHAR(120) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  buyer_id INT NOT NULL,
  seller_id INT NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY (item_id, buyer_id),
  FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
  FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  sender_id INT NOT NULL,
  body TEXT NOT NULL,
  offer_amount DECIMAL(10,2) NULL,
  offer_status ENUM('pending','accepted','declined') NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  body VARCHAR(255) NOT NULL,
  link VARCHAR(255) NOT NULL DEFAULT '',
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  action VARCHAR(60) NOT NULL,
  target_type VARCHAR(30) NOT NULL DEFAULT '',
  target_id INT NULL,
  details VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX (created_at),
  FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
SQL);

// ---- Migrations for databases created by an earlier version ----
$hasCol = function (string $table, string $col) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([DB_NAME, $table, $col]);
    return (bool)$st->fetchColumn();
};
if (!$hasCol('users', 'role')) {
    $pdo->exec("ALTER TABLE users ADD role ENUM('user','admin') NOT NULL DEFAULT 'user' AFTER wallet_balance,
                ADD banned TINYINT(1) NOT NULL DEFAULT 0 AFTER role, ADD ban_reason VARCHAR(255) NOT NULL DEFAULT '' AFTER banned");
    say('Migrated users table (role, banned).');
}
$pdo->exec("ALTER TABLE items MODIFY status ENUM('active','reserved','sold','hidden','removed') NOT NULL DEFAULT 'active'");

// ---- v3: live-site features (payments, shipping, email, security) ----
$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(20) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(20) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  identifier VARCHAR(190) NOT NULL DEFAULT '',
  success TINYINT(1) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (action, ip, created_at),
  INDEX (action, identifier, created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  to_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NOT NULL,
  status ENUM('queued','sent','failed','logged') NOT NULL,
  attempts TINYINT NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  INDEX (status, id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS pudo_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  point_id INT NOT NULL,
  order_id INT NULL,
  type VARCHAR(20) NOT NULL,
  user_id INT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX (point_id, created_at),
  FOREIGN KEY (point_id) REFERENCES pickup_points(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS webhook_events (
  id VARCHAR(100) PRIMARY KEY,
  provider VARCHAR(20) NOT NULL,
  type VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;
SQL);
$cols = [
    'users' => [
        'email_verified_at' => 'DATETIME NULL', 'terms_accepted_at' => 'DATETIME NULL', 'last_login_at' => 'DATETIME NULL',
        'totp_secret' => 'VARCHAR(64) NULL', 'totp_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'totp_last_counter' => 'BIGINT NOT NULL DEFAULT 0',
        'email_notifications' => 'TINYINT(1) NOT NULL DEFAULT 1', 'stripe_account_id' => 'VARCHAR(64) NULL',
        'payouts_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'deleted_at' => 'DATETIME NULL',
        'lat' => 'DECIMAL(9,6) NULL', 'lng' => 'DECIMAL(9,6) NULL', 'geocoded_at' => 'DATETIME NULL',
    ],
    'orders' => [
        'payment_mode' => "VARCHAR(10) NOT NULL DEFAULT 'demo'", 'stripe_session' => 'VARCHAR(255) NULL', 'stripe_payment_intent' => 'VARCHAR(64) NULL',
        'stripe_charge' => 'VARCHAR(64) NULL', 'stripe_transfer' => 'VARCHAR(64) NULL', 'stripe_refund' => 'VARCHAR(64) NULL',
        'shippo_transaction' => 'VARCHAR(64) NULL', 'label_url' => 'VARCHAR(500) NULL', 'tracking_url' => 'VARCHAR(500) NULL',
        'label_cost' => 'DECIMAL(10,2) NULL', 'paid_at' => 'DATETIME NULL', 'shipped_at' => 'DATETIME NULL', 'delivered_at' => 'DATETIME NULL',
        'completed_at' => 'DATETIME NULL', 'reminder_sent' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'pickup_code' => 'CHAR(6) NULL', 'at_point_at' => 'DATETIME NULL', 'pickup_deadline' => 'DATETIME NULL', 'collected_at' => 'DATETIME NULL',
        'pickup_reminded' => 'TINYINT(1) NOT NULL DEFAULT 0', 'dropoff_point_id' => 'INT NULL', 'dropped_off_at' => 'DATETIME NULL',
        'return_due' => 'TINYINT(1) NOT NULL DEFAULT 0', 'return_label_url' => 'VARCHAR(500) NULL', 'return_tracking' => 'VARCHAR(40) NULL',
        'returned_at' => 'DATETIME NULL',
    ],
    'pickup_points' => [
        'state' => "CHAR(2) NOT NULL DEFAULT ''",
        'status' => "ENUM('pending','active','paused','rejected') NOT NULL DEFAULT 'active'",
        'partner_user_id' => 'INT NULL', 'contact_name' => "VARCHAR(120) NOT NULL DEFAULT ''", 'contact_phone' => "VARCHAR(30) NOT NULL DEFAULT ''",
        'contact_email' => "VARCHAR(190) NOT NULL DEFAULT ''", 'lat' => 'DECIMAL(9,6) NULL', 'lng' => 'DECIMAL(9,6) NULL',
        'capacity' => 'INT NOT NULL DEFAULT 30', 'accepts_pickup' => 'TINYINT(1) NOT NULL DEFAULT 1', 'accepts_dropoff' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'notes' => "VARCHAR(500) NOT NULL DEFAULT ''", 'review_note' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'applied_at' => 'DATETIME NULL', 'approved_at' => 'DATETIME NULL',
    ],
];
$added = 0;
foreach ($cols as $table => $defs) {
    foreach ($defs as $col => $def) {
        if (!$hasCol($table, $col)) {
            $pdo->exec("ALTER TABLE `$table` ADD `$col` $def");
            $added++;
            if ($table === 'users' && $col === 'email_verified_at') $pdo->exec('UPDATE users SET email_verified_at = created_at');   // existing accounts count as verified
        }
    }
}
$pdo->exec("ALTER TABLE orders MODIFY status ENUM('pending_payment','paid','label_sent','shipped','at_pickup','delivered','completed','canceled','suspended') NOT NULL DEFAULT 'paid'");
$idx = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = '" . DB_NAME . "' AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_pi'")->fetchColumn();
if (!$idx) $pdo->exec('ALTER TABLE orders ADD INDEX idx_pi (stripe_payment_intent), ADD INDEX idx_tracking (tracking)');
if ($added) say("Added $added new columns for payments, shipping, email and security.");

// ---- Performance: feed sort column, listing indexes and full-text search ----
// sort_at is the feed position (bump time, else listing time). It is computed by MySQL,
// so the app never writes it, and it can be indexed, unlike COALESCE() in ORDER BY.
if (!$hasCol('items', 'sort_at')) {
    $pdo->exec('ALTER TABLE items ADD sort_at DATETIME AS (COALESCE(bumped_at, created_at)) STORED');
}
$hasIdx = function (string $table, string $index) use ($pdo): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([DB_NAME, $table, $index]);
    return (bool)$st->fetchColumn();
};
$perfIdx = [
    ['items', 'idx_feed', 'INDEX idx_feed (status, sort_at)'],                            // home page, "All items"
    ['items', 'idx_cat_feed', 'INDEX idx_cat_feed (category_id, status, sort_at)'],       // category pages, similar items
    ['items', 'idx_user_feed', 'INDEX idx_user_feed (user_id, status, sort_at)'],         // member pages, "more from this seller"
    ['items', 'idx_price', 'INDEX idx_price (status, price)'],                            // price sorts
    ['items', 'ft_search', 'FULLTEXT INDEX ft_search (title, brand, description)'],       // search box
    ['item_photos', 'idx_cover', 'INDEX idx_cover (item_id, sort, id)'],                  // cover photo per card
];
$newIdx = 0;
foreach ($perfIdx as [$table, $name, $def]) {
    if (!$hasIdx($table, $name)) { $pdo->exec("ALTER TABLE `$table` ADD $def"); $newIdx++; }   // one at a time: InnoDB adds one FULLTEXT per ALTER
}
if ($newIdx) say("Added $newIdx performance indexes (feed, categories, prices, search).");

// ---- Admin account (test credentials, see README) ----
if (!$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn()
    && !$pdo->query("SELECT COUNT(*) FROM users WHERE email = 'admin@example.com'")->fetchColumn()) {
    $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, avatar_color, role, email_verified_at, created_at) VALUES ('admin', 'admin@example.com', ?, 'Site Admin', '#444c56', 'admin', NOW(), NOW())")
        ->execute([password_hash('admin1234', PASSWORD_DEFAULT)]);
    say('Created admin account: admin@example.com / admin1234');
}
say('Tables ready.');

if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin'")->fetchColumn() > 0) {
    say('Data already present: skipped seeding. Use ?reset=1 (or --reset) to rebuild.');
} else {
    seed($pdo);
}

function seed(PDO $pdo): void
{
    // ---- Categories ----
    $tree = [
        'Women' => ['icon' => 'shirt', 'children' => [
            'Clothing' => ['Dresses', 'Tops & T-shirts', 'Sweaters', 'Jeans', 'Pants', 'Skirts', 'Outerwear', 'Activewear'],
            'Shoes' => ['Sneakers', 'Boots', 'Heels', 'Flats & sandals'],
            'Bags' => ['Handbags', 'Backpacks', 'Wallets'],
            'Accessories' => ['Jewelry', 'Hats', 'Scarves', 'Belts'],
            'Beauty' => [],
        ]],
        'Men' => ['icon' => 'user', 'children' => [
            'Clothing' => [
                'Jeans' => ['Ripped jeans', 'Skinny jeans', 'Slim fit jeans', 'Straight fit jeans'],
                'Outerwear' => ['Jackets', 'Coats', 'Vests'],
                'Tops & T-shirts' => ['T-shirts', 'Shirts', 'Polos'],
                'Suits & blazers' => ['Suits', 'Blazers'],
                'Sweaters & sweatshirts' => ['Sweaters', 'Hoodies', 'Quarter-zips'],
                'Pants' => ['Chinos', 'Joggers', 'Dress pants'],
                'Shorts' => ['Casual shorts', 'Athletic shorts'],
                'Socks & underwear' => ['Socks', 'Underwear', 'Shapewear'],
                'Sleepwear' => ['Pajamas', 'Robes'],
                'Swimwear' => [],
                'Activewear' => ['Tops', 'Bottoms'],
                'Costumes & special outfits' => [],
                'Other clothing' => [],
            ],
            'Shoes' => ['Sneakers', 'Boots', 'Dress shoes', 'Sandals'],
            'Accessories' => ['Watches', 'Hats', 'Belts', 'Bags'],
            'Grooming' => [],
        ]],
        'Kids' => ['icon' => 'baby', 'children' => [
            'Baby (0-24M)' => ['Bodysuits', 'Outfits & sets', 'Sleepwear'],
            'Girls clothing' => [], 'Boys clothing' => [], 'Toys' => [],
        ]],
        'Home' => ['icon' => 'sofa', 'children' => ['Decor' => [], 'Kitchen' => [], 'Bedding' => []]],
        'Entertainment' => ['icon' => 'book', 'children' => ['Books' => [], 'Video games' => [], 'Music' => []]],
        'Sports' => ['icon' => 'ball', 'children' => [
            'Golf' => ['Golf clubs', 'Golf balls', 'Golf shoes'], 'Cycling' => [], 'Fitness' => [], 'Outdoor' => [],
        ]],
    ];
    $secondIcons = ['Clothing' => 'shirt', 'Shoes' => 'shoe', 'Accessories' => 'watch', 'Grooming' => 'bottle', 'Bags' => 'bag', 'Beauty' => 'bottle'];
    $catIds = [];
    $ins = $pdo->prepare('INSERT INTO categories (parent_id, name, icon, sort) VALUES (?, ?, ?, ?)');
    $add = function ($parentId, $name, $icon, $sort, $path) use ($ins, $pdo, &$catIds) {
        $ins->execute([$parentId, $name, $icon, $sort]);
        return $catIds[$path] = (int)$pdo->lastInsertId();
    };
    $walk = function ($parentId, array $nodes, string $prefix, int $depth) use (&$walk, $add, $secondIcons) {
        $i = 0;
        foreach ($nodes as $k => $v) {
            $name = is_int($k) ? $v : $k;
            $children = is_int($k) ? [] : $v;
            $path = $prefix . '/' . $name;
            $id = $add($parentId, $name, $depth === 1 ? ($secondIcons[$name] ?? null) : null, $i++, $path);
            if ($children) $walk($id, $children, $path, $depth + 1);
        }
    };
    $i = 0;
    foreach ($tree as $top => $node) {
        $id = $add(null, $top, $node['icon'], $i++, $top);
        $walk($id, $node['children'], $top, 1);
    }

    // ---- Users ----  (Demo login: demo@example.com / demo1234 — test data only)
    $users = [
        ['demo', 'demo@example.com', 'Demo Shopper', '+15550100100', '100 Example Street Apt 4', 'Portland', 'OR', '97201', '#c46a3a', 0],
        ['sam_thrift', 'sam@example.com', 'Sam Rivera', '+15550100101', '22 Sample Ave', 'Portland', 'OR', '97214', '#3a7cc4', 1],
        ['maria.closet', 'maria@example.com', 'Maria Chen', '+15550100102', '9 Test Road', 'Seattle', 'WA', '98101', '#8d4cc2', 1],
        ['jordanlee', 'jordan@example.com', 'Jordan Lee', '+15550100103', '415 Mock Blvd', 'Boise', 'ID', '83702', '#3f8f4f', 0],
        ['bookworm_kate', 'kate@example.com', 'Kate Moss', '+15550100104', '7 Placeholder Ln', 'Eugene', 'OR', '97401', '#c43a63', 1],
        ['golfpro_dan', 'dan@example.com', 'Dan Ortiz', '+15550100105', '80 Fairway Dr', 'Bend', 'OR', '97701', '#b0902e', 0],
    ];
    $uIns = $pdo->prepare('INSERT INTO users (username, email, password_hash, full_name, phone, address_line, city, state, zip, avatar_color, bundle_enabled, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? DAY)');
    $uid = [];
    foreach ($users as $n => $u) {
        $uIns->execute([$u[0], $u[1], password_hash($u[0] === 'demo' ? 'demo1234' : 'password123', PASSWORD_DEFAULT), $u[2], $u[3], $u[4], $u[5], $u[6], $u[7], $u[8], $u[9], 30 + $n * 40]);
        $uid[$u[0]] = (int)$pdo->lastInsertId();
    }

    // ---- Items ----  [seller, category path, title, brand, size, condition, colors, material, price, parcel, hours ago, bumped]
    $items = [
        ['sam_thrift', 'Men/Clothing/Jeans/Straight fit jeans', 'Light wash straight jeans', 'Calvin Klein', 'W32', 'Very good', 'Blue', 'Denim', 9.99, 'M', 3, 1],
        ['maria.closet', 'Men/Clothing/Jeans/Straight fit jeans', 'Dusty rose straight jeans', 'Express', 'W33', 'Very good', 'Pink', 'Denim', 3.00, 'M', 5, 1],
        ['jordanlee', 'Men/Clothing/Jeans/Straight fit jeans', 'Classic relaxed jeans', 'Wrangler', 'W36', 'Good', 'Blue', 'Denim', 7.50, 'M', 20, 0],
        ['sam_thrift', 'Men/Clothing/Jeans/Slim fit jeans', 'Dark indigo slim jeans', 'Levi\'s', 'W30', 'Very good', 'Navy', 'Denim', 14.00, 'M', 30, 0],
        ['jordanlee', 'Men/Clothing/Jeans/Ripped jeans', 'Distressed black jeans', 'Zara', 'W31', 'Good', 'Black', 'Denim', 8.00, 'M', 50, 0],
        ['maria.closet', 'Men/Clothing/Sweaters & sweatshirts/Sweaters', 'Black snowflake knit sweater', 'Karen Scott', 'M', 'Very good', 'Black,Red', 'Wool', 3.00, 'M', 8, 1],
        ['sam_thrift', 'Men/Clothing/Sweaters & sweatshirts/Sweaters', 'Fair isle crewneck sweater', 'Old Navy', 'L', 'Good', 'Beige,Black', 'Cotton', 3.00, 'M', 12, 0],
        ['jordanlee', 'Men/Clothing/Sweaters & sweatshirts/Quarter-zips', 'Gray performance quarter-zip', 'Nike', 'M', 'Very good', 'Gray', 'Polyester', 12.00, 'S', 15, 0],
        ['sam_thrift', 'Men/Clothing/Outerwear/Jackets', 'Plaid shirt jacket', 'Wrangler', 'L', 'Very good', 'Green,Brown', 'Wool', 6.50, 'M', 13, 0],
        ['maria.closet', 'Men/Clothing/Pants/Chinos', 'Khaki chinos', 'Nordstrom', '34', 'Good', 'Beige', 'Cotton', 2.00, 'M', 16, 0],
        ['sam_thrift', 'Men/Clothing/Tops & T-shirts/Shirts', 'Fleece button up', 'Columbia', 'M', 'Very good', 'Black,White', 'Fleece', 3.00, 'M', 40, 0],
        ['jordanlee', 'Men/Clothing/Socks & underwear/Shapewear', 'Compression undershirts (2 pack)', 'Equmen', 'L', 'New without tags', 'Black,White', 'Nylon', 2.40, 'S', 10, 0],
        ['maria.closet', 'Men/Clothing/Suits & blazers/Blazers', 'Navy wool blazer', 'J.Crew', '40R', 'Very good', 'Navy', 'Wool', 24.00, 'L', 60, 0],
        ['jordanlee', 'Men/Shoes/Sneakers', 'White leather sneakers', 'Adidas', '10', 'Good', 'White', 'Leather', 18.00, 'M', 70, 0],
        ['golfpro_dan', 'Men/Shoes/Sneakers', 'Golf spikes white/brown', 'FootJoy', '10.5', 'Good', 'White,Brown', 'Leather', 13.50, 'M', 15, 0],
        ['golfpro_dan', 'Sports/Golf/Golf clubs', 'Vintage persimmon driver', 'MacGregor', 'One size', 'Good', 'Brown', 'Wood', 22.00, 'L', 26, 0],
        ['golfpro_dan', 'Sports/Golf/Golf balls', 'Golf balls, 24 count', 'Titleist', 'One size', 'Very good', 'White', 'Plastic', 9.00, 'S', 34, 1],
        ['golfpro_dan', 'Sports/Golf/Golf shoes', 'Waterproof golf shoes', 'Callaway', '9', 'Very good', 'Black', 'Leather', 16.00, 'M', 90, 0],
        ['bookworm_kate', 'Entertainment/Books', 'Real estate investing paperback', 'Self-published', 'One size', 'Very good', 'Multi', 'Paper', 2.00, 'S', 18, 0],
        ['bookworm_kate', 'Entertainment/Books', 'Mystery novel bundle (5 books)', 'Various', 'One size', 'Good', 'Multi', 'Paper', 6.00, 'M', 5, 1],
        ['bookworm_kate', 'Entertainment/Books', 'Cookbook: weeknight dinners', 'Penguin', 'One size', 'Very good', 'Multi', 'Paper', 3.50, 'S', 44, 0],
        ['bookworm_kate', 'Entertainment/Video games', 'Kart racing game cartridge', 'Generic', 'One size', 'Good', 'Multi', 'Plastic', 11.00, 'S', 80, 0],
        ['maria.closet', 'Kids/Baby (0-24M)/Outfits & sets', 'Infant formal wear set', 'Little Gent', '3-6M', 'Very good', 'Black,White', 'Cotton', 5.00, 'S', 13, 0],
        ['maria.closet', 'Kids/Baby (0-24M)/Bodysuits', 'Organic cotton bodysuits (3)', 'Carter\'s', '6-9M', 'New with tags', 'White', 'Cotton', 7.00, 'S', 22, 0],
        ['maria.closet', 'Women/Clothing/Dresses', 'Floral midi dress', 'Madewell', 'S', 'Very good', 'Multi', 'Linen', 19.00, 'M', 6, 1],
        ['maria.closet', 'Women/Clothing/Sweaters', 'Cream cable knit sweater', 'Gap', 'M', 'Good', 'Beige', 'Wool', 11.00, 'M', 28, 0],
        ['jordanlee', 'Women/Bags/Handbags', 'Tan leather crossbody', 'Fossil', 'One size', 'Very good', 'Brown', 'Leather', 25.00, 'M', 36, 0],
        ['maria.closet', 'Women/Shoes/Boots', 'Black ankle boots', 'Steve Madden', '8', 'Good', 'Black', 'Leather', 21.00, 'L', 48, 0],
        ['sam_thrift', 'Men/Accessories/Watches', 'Stainless field watch', 'Timex', 'One size', 'Very good', 'Gray', 'Metal', 29.00, 'S', 55, 0],
        ['sam_thrift', 'Home/Decor', 'Ceramic table lamp', 'West Elm', 'One size', 'Very good', 'White', 'Other', 15.00, 'L', 65, 0],
        ['demo', 'Men/Clothing/Tops & T-shirts/T-shirts', 'Graphic band tee', 'Hanes', 'L', 'Good', 'Black', 'Cotton', 6.00, 'S', 24, 0],
        ['demo', 'Sports/Golf/Golf clubs', 'Wood fairway club', 'Unbranded', 'One size', 'Good', 'Brown', 'Wood', 12.00, 'L', 48, 0],
    ];
    $iIns = $pdo->prepare('INSERT INTO items (user_id, category_id, title, description, brand, size, condition_label, colors, material, price, parcel_size, views, bumped_at, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() - INTERVAL ? HOUR)');
    $pIns = $pdo->prepare('INSERT INTO item_photos (item_id, path, sort) VALUES (?, ?, ?)');
    $itemIds = [];
    foreach ($items as $n => $it) {
        $desc = "{$it[2]} by {$it[3]}. Condition: " . strtolower($it[5]) . ". Smoke-free home, ships within 2 days. Bundle to save on shipping!";
        $iIns->execute([$uid[$it[0]], $catIds[$it[1]], $it[2], $desc, $it[3], $it[4], $it[5], $it[6], $it[7], $it[8], $it[9],
                        random_int(5, 140), $it[11] ? date('Y-m-d H:i:s', time() - 3600) : null, $it[10]]);
        $id = (int)$pdo->lastInsertId();
        $itemIds[] = $id;
        for ($p = 0; $p < 2; $p++) $pIns->execute([$id, 'placeholder.php?seed=' . $id . '-' . $p . '&cat=' . urlencode($it[1]) . '&c=' . urlencode(explode(',', $it[6])[0]), $p]);
    }

    // ---- Favorites ----
    $fIns = $pdo->prepare('INSERT IGNORE INTO favorites (user_id, item_id, created_at) VALUES (?, ?, NOW())');
    foreach ($itemIds as $id) {
        foreach ($uid as $u) if (random_int(0, 3) === 0) $fIns->execute([$u, $id]);
    }
    foreach ([1, 23, 16, 29] as $n) $fIns->execute([$uid['demo'], $itemIds[$n - 1]]);

    // ---- Pick-up points ----
    $pp = $pdo->prepare('INSERT INTO pickup_points (name, address, city, zip, hours) VALUES (?, ?, ?, ?, ?)');
    foreach ([
        ['The Corner Nursery', '450 SE Sample Ave #103', 'Portland', '97233', 'Mon-Sat 9am-7pm'],
        ['Hawthorne Mail & Copy', '3600 SE Example Blvd', 'Portland', '97214', 'Mon-Fri 8am-6pm'],
        ['Pearl Locker Hub', '1100 NW Test St', 'Portland', '97209', 'Open 24/7'],
        ['Belmont Pharmacy', '2900 SE Placeholder St', 'Portland', '97214', 'Daily 8am-9pm'],
    ] as $p) $pp->execute($p);

    // ---- Orders for demo user ----
    $order = function (string $buyer, string $seller, int $itemNo, string $status, string $type, string $carrier) use ($pdo, $uid, $itemIds, $items) {
        $it = $items[$itemNo - 1];
        $price = $it[8];
        $fee = round(FEE_FIXED + $price * FEE_RATE, 2);
        $ship = [
            'pickup' => ['S' => 0.00, 'M' => 1.99, 'L' => 4.49], 'usps' => ['S' => 1.49, 'M' => 4.99, 'L' => 8.99], 'fedex' => ['S' => 2.99, 'M' => 5.99, 'L' => 9.99],
        ][$carrier][$it[9]];
        $b = $pdo->query('SELECT * FROM users WHERE id = ' . (int)$uid[$buyer])->fetch();
        $pdo->prepare('INSERT INTO orders (buyer_id, seller_id, subtotal, buyer_fee, shipping_cost, total, delivery_type, carrier, parcel_size, pickup_point_id,
                         ship_name, ship_address, ship_city, ship_state, ship_zip, contact_phone, status, tracking, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW() - INTERVAL 1 DAY, NOW())')
            ->execute([$uid[$buyer], $uid[$seller], $price, $fee, $ship, $price + $fee + $ship, $type, $carrier, $it[9], $type === 'pickup' ? 1 : null,
                       $b['full_name'], $b['address_line'], $b['city'], $b['state'], $b['zip'], $b['phone'], $status,
                       in_array($status, ['label_sent', 'shipped', 'completed'], true) ? 'RT' . random_int(100000000, 999999999) : null]);
        $oid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO order_items (order_id, item_id, title, price) VALUES (?,?,?,?)')->execute([$oid, $itemIds[$itemNo - 1], $it[2], $price]);
        $pdo->prepare("UPDATE items SET status = 'sold' WHERE id = ?")->execute([$itemIds[$itemNo - 1]]);
        return $oid;
    };
    $order('demo', 'sam_thrift', 9, 'label_sent', 'home', 'usps');
    $order('demo', 'maria.closet', 10, 'label_sent', 'pickup', 'pickup');
    $order('demo', 'bookworm_kate', 19, 'paid', 'pickup', 'pickup');
    $order('demo', 'sam_thrift', 11, 'shipped', 'pickup', 'pickup');
    $order('demo', 'jordanlee', 12, 'shipped', 'home', 'usps');
    $order('maria.closet', 'jordanlee', 14, 'suspended', 'home', 'usps');
    $order('jordanlee', 'golfpro_dan', 17, 'completed', 'pickup', 'pickup');
    $pdo->exec("UPDATE users SET wallet_balance = 9.00 WHERE username = 'golfpro_dan'");
    $o = $order('jordanlee', 'demo', 31, 'paid', 'home', 'usps');
    $pdo->prepare('INSERT INTO notifications (user_id, body, link, created_at) VALUES (?, ?, ?, NOW())')
        ->execute([$uid['demo'], 'jordanlee bought "Graphic band tee". Get the shipping label.', 'order.php?id=' . $o]);

    // ---- Conversations ----
    $conv = function (string $buyer, int $itemNo, array $msgs) use ($pdo, $uid, $itemIds, $items) {
        $seller = $uid[$items[$itemNo - 1][0]];
        $pdo->prepare('INSERT INTO conversations (item_id, buyer_id, seller_id, updated_at) VALUES (?,?,?, NOW())')->execute([$itemIds[$itemNo - 1], $uid[$buyer], $seller]);
        $cid = (int)$pdo->lastInsertId();
        $mIns = $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body, offer_amount, offer_status, read_at, created_at) VALUES (?,?,?,?,?,?, NOW() - INTERVAL ? MINUTE)');
        $age = count($msgs) * 30;
        foreach ($msgs as $m) {
            [$who, $body, $offer, $ostatus] = $m + [2 => null, 3 => null];
            $mIns->execute([$cid, $who === 'buyer' ? $uid[$buyer] : $seller, $body, $offer, $ostatus, null, $age]);
            $age -= 30;
        }
    };
    $conv('demo', 20, [['seller', 'Hi! I posted more book bundles if interested']]);
    $conv('demo', 15, [['buyer', 'Would you take $12 for these?', 12.00, 'declined'], ['seller', 'Sorry, lowest I can go is $13.50']]);
    $conv('demo', 23, [['buyer', 'Is this still available?'], ['seller', 'Yes it is!']]);
    $conv('demo', 8, [['buyer', 'How does the sizing run?'], ['seller', 'True to size, fits like a regular M.']]);

    // Mark demo-sent messages read so unread counts only reflect incoming ones.
    $pdo->prepare('UPDATE messages SET read_at = NOW() WHERE sender_id = ?')->execute([$uid['demo']]);
    $pdo->exec("UPDATE users SET email_verified_at = NOW(), terms_accepted_at = NOW() WHERE email_verified_at IS NULL");
    $pdo->exec("UPDATE pickup_points SET state = 'OR', applied_at = NOW() - INTERVAL 60 DAY, approved_at = NOW() - INTERVAL 59 DAY, contact_phone = '+15550100190'");
    foreach ([1 => [45.519200, -122.497300, 40], 2 => [45.512100, -122.625500, 25], 3 => [45.530300, -122.684200, 60], 4 => [45.516500, -122.635100, 20]] as $pid => [$la, $ln, $cap]) {
        $pdo->prepare('UPDATE pickup_points SET lat = ?, lng = ?, capacity = ? WHERE id = ?')->execute([$la, $ln, $cap, $pid]);
    }
    $pdo->exec("UPDATE pickup_points SET accepts_pickup = 0 WHERE id = 3");   // drop-off only locker
    $pdo->prepare("INSERT INTO pickup_points (name, address, city, state, zip, hours, lat, lng, capacity, status, contact_phone, applied_at, approved_at) VALUES
                   ('Capitol Hill Pack & Ship', '1500 E Example St', 'Seattle', 'WA', '98122', 'Mon-Sat 10am-7pm', 47.614800, -122.312000, 30, 'active', '+15550100191', NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 29 DAY)")->execute();
    // Approximate coordinates for demo members (their addresses are fictional, so the geocoder can't find them).
    foreach (['demo' => [45.507, -122.690], 'sam_thrift' => [45.514, -122.643], 'maria.closet' => [47.610, -122.336], 'jordanlee' => [43.632, -116.205],
              'bookworm_kate' => [44.065, -123.083], 'golfpro_dan' => [44.058, -121.315]] as $un => [$la, $ln]) {
        $pdo->prepare('UPDATE users SET lat = ?, lng = ?, geocoded_at = NOW() WHERE username = ?')->execute([$la, $ln, $un]);
    }
    // A shop owner who runs pick-up point #1 (test login: shop@example.com / password123).
    $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, phone, address_line, city, state, zip, avatar_color, email_verified_at, terms_accepted_at, created_at)
                   VALUES ('cornernursery', 'shop@example.com', ?, 'Pat Green', '+15550100190', '450 SE Sample Ave #103', 'Portland', 'OR', '97233', '#2f8f6f', NOW(), NOW(), NOW() - INTERVAL 60 DAY)")
        ->execute([password_hash('password123', PASSWORD_DEFAULT)]);
    $shopUser = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE pickup_points SET partner_user_id = ?, contact_name = 'Pat Green', contact_email = 'shop@example.com' WHERE id = 1")->execute([$shopUser]);
    // A pending application from another shop, so the admin queue isn't empty.
    $pdo->prepare("INSERT INTO pickup_points (name, address, city, state, zip, hours, capacity, status, partner_user_id, contact_name, contact_phone, contact_email, notes, applied_at)
                   VALUES ('Division St Hardware', '3200 SE Division St', 'Portland', 'OR', '97202', 'Daily 9am-6pm', 25, 'pending', ?, 'Sam Rivera', '+15550100101', 'sam@example.com', 'We have a back room with shelving for about 25 parcels.', NOW() - INTERVAL 2 DAY)")
        ->execute([$uid['sam_thrift']]);
    // Order #2 (demo bought khaki chinos, pick-up) is now waiting at the shop.
    $pdo->exec("UPDATE orders SET status = 'at_pickup', pickup_code = '482915', shipped_at = NOW() - INTERVAL 3 DAY, at_point_at = NOW() - INTERVAL 1 DAY,
                pickup_deadline = NOW() + INTERVAL 6 DAY WHERE id = 2");
    $pdo->exec("INSERT INTO pudo_events (point_id, order_id, type, user_id, created_at) VALUES (1, 2, 'received', $shopUser, NOW() - INTERVAL 1 DAY)");
    $pdo->exec("UPDATE orders SET paid_at = created_at WHERE status <> 'pending_payment'");
    say('Seeded ' . count($catIds) . ' categories, ' . count($users) . ' users, ' . count($items) . ' items, orders and messages.');
    say('Demo login: demo@example.com / demo1234 (other seed users use password123).');
}

if ($cli) {
    echo implode(PHP_EOL, $out) . PHP_EOL;
} else {
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install</title>'
       . '<body style="font-family:system-ui;background:#16191c;color:#eee;padding:24px;line-height:1.6">'
       . '<h2>' . APP_NAME . ' install</h2><ul>';
    foreach ($out as $l) echo '<li>' . htmlspecialchars($l) . '</li>';
    echo '</ul><p><a style="color:#f2a65a" href="index.php">Open the app →</a></p></body>';
}
