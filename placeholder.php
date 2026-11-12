<?php
// Generates a simple product illustration (SVG) for demo listings.
declare(strict_types=1);
header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=86400');

$seed = (string)($_GET['seed'] ?? 'x');
$cat  = strtolower((string)($_GET['cat'] ?? ''));
$col  = (string)($_GET['c'] ?? '');

$palette = [
    'black' => '#23262b', 'white' => '#f1efe9', 'gray' => '#8a8f98', 'beige' => '#cdb48f', 'brown' => '#7a5230',
    'navy' => '#24365f', 'blue' => '#4d7fc9', 'green' => '#4b7d4f', 'red' => '#b8402f', 'pink' => '#c98b8f',
    'purple' => '#7d4cc2', 'yellow' => '#d9b23a', 'orange' => '#d9772e', 'multi' => '#c06b3e',
];
$fill = $palette[strtolower($col)] ?? '#6b7f8e';
$h = crc32($seed);
$bgs = ['#e9e4dc', '#dcd6cc', '#e4e7ea', '#d9d2c5', '#ece8e1', '#d6dadf'];
$bg = $bgs[$h % count($bgs)];
$flip = ($h >> 3) % 2 ? 'scale(-1,1) translate(-300,0)' : '';
$stroke = in_array(strtolower($col), ['black', 'navy'], true) ? '#4a4f57' : 'rgba(0,0,0,.25)';
$second = $h % 7 === 0 ? 'rotate(-4 150 200)' : 'rotate(3 150 200)';

$shapes = [
    'jeans'   => '<path d="M95 70h110l12 250h-48l-19-180-19 180H83z"/><path d="M95 95h110" fill="none"/><path d="M150 75v40" fill="none"/>',
    'pants'   => '<path d="M95 70h110l12 250h-48l-19-180-19 180H83z"/><path d="M95 95h110" fill="none"/>',
    'shorts'  => '<path d="M90 110h120l10 120h-55l-15-60-15 60H80z"/>',
    'sweater' => '<path d="M110 70c10 14 70 14 80 0l55 30-15 155-25-5v65H95v-65l-25 5-15-155z"/><path d="M95 165h110M95 185h110" fill="none" stroke-dasharray="6 6"/>',
    'shirt'   => '<path d="M112 70 150 92l38-22 55 32-18 45-27-12v185H102V135l-27 12-18-45z"/><path d="M150 92v220" fill="none"/>',
    'jacket'  => '<path d="M110 66l40 26 40-26 58 34-12 175-28-6v61H92v-61l-28 6-12-175z"/><path d="M150 92v218M118 150h22M160 150h22" fill="none"/>',
    'blazer'  => '<path d="M110 66l40 60 40-60 58 34-12 175-28-6v61H92v-61l-28 6-12-175z"/><path d="M150 126v184" fill="none"/>',
    'dress'   => '<path d="M120 60h60l-8 60 58 200H70l58-200z"/><path d="M128 120h44" fill="none"/>',
    'shoe'    => '<path d="M50 230v-60l50-10 40 35 105 18c12 2 20 12 20 24v13H50z"/><path d="M50 250h215" fill="none"/>',
    'boot'    => '<path d="M95 70h70v130l70 30c10 5 15 12 15 22v18H95z"/>',
    'book'    => '<rect x="85" y="60" width="130" height="190" rx="4"/><path d="M100 60v190M120 110h80M120 130h60" fill="none"/>',
    'ball'    => '<circle cx="150" cy="185" r="70"/><circle cx="130" cy="165" r="4"/><circle cx="160" cy="160" r="4"/><circle cx="175" cy="190" r="4"/><circle cx="140" cy="200" r="4"/>',
    'club'    => '<path d="M150 40v230" fill="none" stroke-width="8"/><path d="M128 262h50c10 0 14 18 4 22l-58 8c-8 1-10-26 4-30z"/>',
    'bag'     => '<path d="M70 140h160l-15 140H85z"/><path d="M110 140c0-45 80-45 80 0" fill="none" stroke-width="8"/>',
    'watch'   => '<rect x="125" y="50" width="50" height="270" rx="12"/><circle cx="150" cy="185" r="45"/><path d="M150 160v25l15 10" fill="none"/>',
    'lamp'    => '<path d="M105 70h90l25 90H80z"/><path d="M150 160v110M110 280h80" fill="none" stroke-width="8"/>',
    'baby'    => '<path d="M115 75 150 90l35-15 40 30-14 30-21-10v65l20 110h-45l-15-70-15 70h-45l20-110v-65l-21 10-14-30z"/>',
    'game'    => '<rect x="90" y="90" width="120" height="160" rx="8"/><rect x="108" y="110" width="84" height="70" rx="4" fill="' . $bg . '"/>',
    'box'     => '<rect x="80" y="100" width="140" height="150" rx="6"/>',
];
$map = [
    'jeans' => 'jeans', 'chinos' => 'pants', 'pants' => 'pants', 'shorts' => 'shorts', 'sweater' => 'sweater', 'quarter' => 'sweater',
    'shapewear' => 'shirt', 'shirts' => 'shirt', 't-shirts' => 'shirt', 'tops' => 'shirt', 'blazer' => 'blazer', 'outerwear' => 'jacket',
    'dresses' => 'dress', 'boots' => 'boot', 'shoes' => 'shoe', 'sneakers' => 'shoe', 'books' => 'book', 'golf balls' => 'ball',
    'golf clubs' => 'club', 'bags' => 'bag', 'watches' => 'watch', 'decor' => 'lamp', 'baby' => 'baby', 'video games' => 'game',
];
$shape = 'box';
foreach ($map as $k => $v) {
    if ($k !== '' && strpos($cat, $k) !== false) { $shape = $v; }
}
if (strpos($cat, 'golf shoes') !== false) $shape = 'shoe';
if (strpos($cat, 'sweaters') !== false) $shape = 'sweater';
$svg = $shapes[$shape];
$multi = ($h >> 5) % 3 === 0 && in_array($shape, ['book', 'shirt', 'ball'], true);
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 380" preserveAspectRatio="xMidYMid slice">
  <rect width="300" height="380" fill="<?= $bg ?>"/>
  <path d="M0 300h300v80H0z" fill="rgba(0,0,0,.05)"/>
  <ellipse cx="150" cy="335" rx="100" ry="10" fill="rgba(0,0,0,.08)"/>
  <?php if ($multi): ?>
  <g transform="<?= $second ?> translate(18,6)" fill="<?= $fill ?>" stroke="<?= $stroke ?>" stroke-width="3" opacity=".55"><?= $svg ?></g>
  <?php endif; ?>
  <g transform="<?= $flip ?>" fill="<?= $fill ?>" stroke="<?= $stroke ?>" stroke-width="3" stroke-linejoin="round"><?= $svg ?></g>
</svg>
