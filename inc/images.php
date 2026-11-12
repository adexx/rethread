<?php
// Re-encodes uploaded photos: fixes rotation, shrinks to PHOTO_MAX_EDGE, and saves a fresh
// JPEG/WebP. Re-encoding drops all metadata (EXIF, including GPS location from phone cameras).

/** @return string|null relative path (uploads/xxx.jpg) or null on failure */
function process_photo(string $tmp, string $mime): ?string
{
    if (!function_exists('imagecreatefromjpeg')) return null;
    switch ($mime) {
        case 'image/jpeg': $img = @imagecreatefromjpeg($tmp); break;
        case 'image/png':  $img = @imagecreatefrompng($tmp); break;
        case 'image/webp': $img = @imagecreatefromwebp($tmp); break;
        case 'image/gif':  $img = @imagecreatefromgif($tmp); break;
        default: return null;
    }
    if (!$img) return null;

    // Apply the camera's orientation flag before it's discarded.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        elseif ($o === 6) $img = imagerotate($img, -90, 0);
        elseif ($o === 8) $img = imagerotate($img, 90, 0);
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w < 200 || $h < 200) { imagedestroy($img); return null; }
    $scale = min(1, PHOTO_MAX_EDGE / max($w, $h));
    $nw = (int)round($w * $scale);
    $nh = (int)round($h * $scale);
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // flatten transparency onto white
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $useWebp = function_exists('imagewebp');
    $name = bin2hex(random_bytes(12)) . ($useWebp ? '.webp' : '.jpg');
    $ok = $useWebp ? imagewebp($out, UPLOAD_DIR . '/' . $name, 82) : imagejpeg($out, UPLOAD_DIR . '/' . $name, 84);
    imagedestroy($out);
    return $ok ? 'uploads/' . $name : null;
}

function delete_photo_file(string $path): void
{
    if (strpos($path, 'uploads/') === 0 && strpos($path, '..') === false) {
        $full = UPLOAD_DIR . '/' . basename($path);
        if (is_file($full)) unlink($full);
    }
}
