<?php
// Re-encodes uploaded photos: fixes rotation, shrinks to PHOTO_MAX_EDGE, and saves a fresh
// JPEG/WebP. Re-encoding drops all metadata (EXIF, including GPS location from phone cameras).

/** @return array|null ['path' => 'uploads/x.webp', 'thumb' => 'uploads/x_t.webp'], or null on failure */
function process_photo(string $tmp, string $mime): ?array
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
    $name = bin2hex(random_bytes(12));
    $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
    $saved = [];
    try {
        // Full-size photo for the item gallery, and a small copy for grids, chats and lists.
        foreach (['path' => [PHOTO_MAX_EDGE, ''], 'thumb' => [THUMB_MAX_EDGE, '_t']] as $k => [$edge, $suffix]) {
            $key = 'uploads/' . $name . $suffix . '.' . $ext;
            $tmpOut = photo_encode($img, $w, $h, $edge, $ext);
            if ($tmpOut === null) throw new RuntimeException('encode failed');
            storage_put($tmpOut, $key, $ext === 'webp' ? 'image/webp' : 'image/jpeg');
            $saved[$k] = $key;
        }
    } catch (Throwable $e) {
        error_log('photo: ' . $e->getMessage());
        foreach ($saved as $key) storage_delete($key);
        $saved = [];
    }
    imagedestroy($img);
    return count($saved) === 2 ? $saved : null;
}

/** Scales an image so its longest side is at most $edge and writes it to a temp file. */
function photo_encode($img, int $w, int $h, int $edge, string $ext): ?string
{
    $scale = min(1, $edge / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // flatten transparency onto white
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $tmp = tempnam(sys_get_temp_dir(), 'rtph');
    $ok = $ext === 'webp' ? imagewebp($out, $tmp, $edge <= THUMB_MAX_EDGE ? 78 : 82) : imagejpeg($out, $tmp, 84);
    imagedestroy($out);
    if (!$ok) { @unlink($tmp); return null; }
    return $tmp;
}

/** Deletes a stored photo and its thumbnail. Accepts a path or an item_photos row. */
function delete_photo_file($photo): void
{
    $paths = is_array($photo) ? [$photo['path'] ?? '', $photo['thumb'] ?? ''] : [$photo];
    foreach ($paths as $p) if ($p) storage_delete($p);
}

/** Deletes the photo files of one item (the item_photos rows go with the item via ON DELETE CASCADE). */
function delete_item_photo_files(int $itemId): void
{
    foreach (q('SELECT path, thumb FROM item_photos WHERE item_id = ?', [$itemId])->fetchAll() as $p) delete_photo_file($p);
}

/** Makes a thumbnail for an existing photo that has none (used by scripts/make_thumbs.php). */
function make_thumb_for(string $path): ?string
{
    if (strpos($path, 'uploads/') !== 0) return null;
    $src = storage_is_s3() ? s3_object_url($path) : UPLOAD_DIR . '/' . basename($path);
    $data = @file_get_contents($src);
    if ($data === false || !($img = @imagecreatefromstring($data))) return null;
    $ext = function_exists('imagewebp') ? 'webp' : 'jpg';
    $key = 'uploads/' . pathinfo($path, PATHINFO_FILENAME) . '_t.' . $ext;
    $tmp = photo_encode($img, imagesx($img), imagesy($img), THUMB_MAX_EDGE, $ext);
    imagedestroy($img);
    if ($tmp === null) return null;
    storage_put($tmp, $key, $ext === 'webp' ? 'image/webp' : 'image/jpeg');
    return $key;
}
