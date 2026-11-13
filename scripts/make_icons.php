<?php
// Draws the app icons and the default link-preview image into assets/icons/.
//   php scripts/make_icons.php "/path/to/a/bold-font.ttf"
// Run once; the PNGs are committed, so servers don't need to run this.
if (PHP_SAPI !== 'cli') exit;
$font = $argv[1] ?? '';
if (!is_file($font) || !function_exists('imagettftext')) exit("Usage: php scripts/make_icons.php /path/to/font.ttf (needs GD with FreeType)\n");
$out = __DIR__ . '/../assets/icons';
if (!is_dir($out)) mkdir($out, 0755, true);
$hex = function ($im, string $h) { return imagecolorallocate($im, hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))); };

$centerText = function ($im, string $text, float $size, int $cx, int $cy, int $color) use ($font) {
    $b = imagettfbbox($size, 0, $font, $text);
    $w = $b[2] - $b[0];
    $h = $b[1] - $b[7];
    imagettftext($im, $size, 0, (int)($cx - $w / 2 - $b[0]), (int)($cy + $h / 2 - $b[1]), $color, $font, $text);
};

// Square icons: "any" has rounded corners on a transparent background; "maskable" fills the square
// and keeps the letter inside the 80% safe zone so Android can crop it to any shape.
foreach ([[192, false], [512, false], [512, true]] as [$size, $maskable]) {
    $im = imagecreatetruecolor($size, $size);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    $accent = $hex($im, '#f2a65a');
    if ($maskable) {
        imagefilledrectangle($im, 0, 0, $size, $size, $accent);
    } else {
        $r = (int)($size * .22);
        imagefilledrectangle($im, $r, 0, $size - $r, $size, $accent);
        imagefilledrectangle($im, 0, $r, $size, $size - $r, $accent);
        foreach ([[$r, $r], [$size - $r - 1, $r], [$r, $size - $r - 1], [$size - $r - 1, $size - $r - 1]] as [$x, $y]) imagefilledellipse($im, $x, $y, $r * 2, $r * 2, $accent);
    }
    $centerText($im, 'r', $size * ($maskable ? .42 : .55), (int)($size / 2), (int)($size / 2), $hex($im, '#16191c'));
    imagepng($im, $out . '/icon-' . $size . ($maskable ? '-maskable' : '') . '.png', 9);
    imagedestroy($im);
}

// 1200×630 preview used when a page has no photo of its own.
$im = imagecreatetruecolor(1200, 630);
imagefill($im, 0, 0, $hex($im, '#16191c'));
imagefilledrectangle($im, 0, 600, 1200, 630, $hex($im, '#f2a65a'));
$centerText($im, 'Rethread', 96, 600, 270, $hex($im, '#eef0f2'));
$centerText($im, 'Second-hand fashion, books and more', 34, 600, 390, $hex($im, '#9aa3ab'));
imagepng($im, $out . '/og-default.png', 9);
imagedestroy($im);
echo "Icons written to assets/icons/\n";
