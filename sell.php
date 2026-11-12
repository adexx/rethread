<?php
require __DIR__ . '/inc/app.php';
$u = require_verified();
if (stripe_enabled() && !$u['payouts_enabled']) {
    flash('Set up payouts first so we can send you the money when your items sell.', 'err');
    redirect('payouts.php?next=sell.php');
}
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$item = null;
$photos = [];
if ($id) {
    $item = q('SELECT * FROM items WHERE id = ? AND user_id = ?', [$id, $u['id']])->fetch();
    if (!$item || in_array($item['status'], ['sold', 'removed'], true)) redirect('sell.php');
    $photos = q('SELECT * FROM item_photos WHERE item_id = ? ORDER BY sort, id', [$id])->fetchAll();
}

// Leaf categories for the picker, grouped by top level.
$leaves = [];
foreach (categories() as $c) {
    if (!category_children((int)$c['id'])) {
        $path = category_path((int)$c['id']);
        $leaves[$path[0]['name']][(int)$c['id']] = implode(' › ', array_map(function ($x) { return $x['name']; }, array_slice($path, 1)));
    }
}

$v = [
    'title' => $item['title'] ?? '', 'description' => $item['description'] ?? '', 'category_id' => (int)($item['category_id'] ?? 0),
    'brand' => $item['brand'] ?? '', 'size' => $item['size'] ?? '', 'condition_label' => $item['condition_label'] ?? '',
    'colors' => $item ? array_filter(explode(',', $item['colors'])) : [], 'material' => $item['material'] ?? '',
    'price' => $item ? number_format((float)$item['price'], 2, '.', '') : '', 'parcel_size' => $item['parcel_size'] ?? '',
];
$errors = [];

if (is_post()) {
    check_csrf();
    $v['title'] = trim((string)($_POST['title'] ?? ''));
    $v['description'] = trim((string)($_POST['description'] ?? ''));
    $v['category_id'] = (int)($_POST['category_id'] ?? 0);
    $v['brand'] = trim((string)($_POST['brand'] ?? ''));
    $v['size'] = trim((string)($_POST['size'] ?? ''));
    $v['condition_label'] = (string)($_POST['condition_label'] ?? '');
    $v['colors'] = array_values(array_intersect(array_keys(COLORS), (array)($_POST['colors'] ?? [])));
    $v['material'] = in_array($_POST['material'] ?? '', MATERIALS, true) ? $_POST['material'] : '';
    $v['price'] = trim((string)($_POST['price'] ?? ''));
    $v['parcel_size'] = (string)($_POST['parcel_size'] ?? '');
    $remove = array_map('intval', (array)($_POST['remove_photo'] ?? []));

    if (mb_strlen($v['title']) < 3 || mb_strlen($v['title']) > 120) $errors[] = 'Title must be 3–120 characters.';
    if (mb_strlen($v['description']) < 5) $errors[] = 'Add a short description (at least 5 characters).';
    $cats = categories();
    if (!isset($cats[$v['category_id']]) || category_children($v['category_id'])) $errors[] = 'Choose a category.';
    if (!isset(CONDITIONS[$v['condition_label']])) $errors[] = 'Choose the condition.';
    if (count($v['colors']) > 2) $errors[] = 'Pick up to 2 colors.';
    if (!is_numeric($v['price']) || (float)$v['price'] < 1 || (float)$v['price'] > 10000) $errors[] = 'Price must be between $1 and $10,000.';
    if (!isset(PARCEL_SIZES[$v['parcel_size']])) $errors[] = 'Choose a parcel size.';
    if ($v['size'] === '') $v['size'] = 'One size';

    // Validate uploads
    $uploads = [];
    $files = $_FILES['photos'] ?? null;
    if ($files && is_array($files['name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        foreach ($files['name'] as $n => $name) {
            if ($files['error'][$n] === UPLOAD_ERR_NO_FILE) continue;
            if ($files['error'][$n] !== UPLOAD_ERR_OK) { $errors[] = 'A photo failed to upload (it may be too large).'; continue; }
            if ($files['size'][$n] > MAX_UPLOAD_BYTES) { $errors[] = 'Each photo must be under 12 MB.'; continue; }
            $mime = $finfo->file($files['tmp_name'][$n]);
            if (!isset($ext[$mime]) || !@getimagesize($files['tmp_name'][$n])) { $errors[] = 'Photos must be JPG, PNG, WEBP or GIF images.'; continue; }
            if (count($uploads) >= MAX_PHOTOS) continue;
            // Resize and re-encode now: strips EXIF/GPS data and anything hidden in the file.
            $path = process_photo($files['tmp_name'][$n], $mime);
            if ($path === null) { $errors[] = 'One photo could not be read or is smaller than 200×200 pixels.'; continue; }
            $uploads[] = $path;
        }
    }
    $keptCount = count(array_filter($photos, function ($p) use ($remove) { return !in_array((int)$p['id'], $remove, true); }));
    if ($keptCount + count($uploads) === 0) $errors[] = 'Add at least one photo.';
    if ($keptCount + count($uploads) > MAX_PHOTOS) $errors[] = 'You can add up to ' . MAX_PHOTOS . ' photos.';

    if ($errors) foreach ($uploads as $path) delete_photo_file($path);   // don't leave orphaned files
    if (!$errors) {
        $data = [$v['category_id'], $v['title'], $v['description'], $v['brand'], $v['size'], $v['condition_label'], implode(',', $v['colors']), $v['material'], round((float)$v['price'], 2), $v['parcel_size']];
        if ($item) {
            q('UPDATE items SET category_id=?, title=?, description=?, brand=?, size=?, condition_label=?, colors=?, material=?, price=?, parcel_size=? WHERE id = ?', array_merge($data, [$id]));
        } else {
            q("INSERT INTO items (category_id, title, description, brand, size, condition_label, colors, material, price, parcel_size, user_id, status, created_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?, 'active', NOW())", array_merge($data, [$u['id']]));
            $id = (int)db()->lastInsertId();
        }
        foreach ($photos as $p) {
            if (in_array((int)$p['id'], $remove, true)) {
                delete_photo_file($p['path']);
                q('DELETE FROM item_photos WHERE id = ?', [$p['id']]);
            }
        }
        $sort = (int)q('SELECT COALESCE(MAX(sort), -1) + 1 FROM item_photos WHERE item_id = ?', [$id])->fetchColumn();
        foreach ($uploads as $path) {
            q('INSERT INTO item_photos (item_id, path, sort) VALUES (?, ?, ?)', [$id, $path, $sort++]);
        }
        flash($item ? 'Listing updated.' : 'Your item is live!');
        redirect('item.php?id=' . $id);
    }
}

page_start($item ? 'Edit listing' : 'Sell an item', ['back' => $item ? 'item.php?id=' . $id : 'index.php', 'close' => true, 'nav' => false]);
?>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <?php if ($item): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
  <?php if ($errors): ?><div class="flash flash-err"><?php foreach ($errors as $er) echo e($er) . '<br>'; ?></div><?php endif; ?>

  <label class="photo-drop" for="photos">
    <?= icon('camera', 32) ?>
    <span>Upload photos</span>
    <span class="muted tiny">Up to <?= MAX_PHOTOS ?> · JPG, PNG, WEBP · 12 MB each · location data is removed</span>
  </label>
  <input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
  <div class="photo-previews" id="new-previews"></div>
  <?php if ($photos): ?>
    <div class="photo-previews" style="margin-top:8px">
      <?php foreach ($photos as $p): ?>
        <figure><img src="<?= e($p['path']) ?>" alt=""><label><input type="checkbox" name="remove_photo[]" value="<?= (int)$p['id'] ?>">Remove</label></figure>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="pad">
    <label class="field"><span>Title</span><input class="input" name="title" maxlength="120" value="<?= e($v['title']) ?>" placeholder="e.g. White COS jumper" required></label>
    <label class="field"><span>Describe your item</span><textarea class="input" name="description" maxlength="2000" placeholder="e.g. only worn a few times, true to size" required><?= e($v['description']) ?></textarea></label>
  </div>

  <h2 class="section-title flush">Item details</h2>
  <label class="sell-row"><span>Category</span>
    <select name="category_id" required>
      <option value="">Select</option>
      <?php foreach ($leaves as $top => $opts): ?>
        <optgroup label="<?= e($top) ?>">
          <?php foreach ($opts as $cid => $label): ?><option value="<?= $cid ?>" <?= $v['category_id'] === $cid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="sell-row"><span>Brand</span><input name="brand" maxlength="80" value="<?= e($v['brand']) ?>" placeholder="Add"></label>
  <label class="sell-row"><span>Size</span><input name="size" maxlength="30" value="<?= e($v['size'] === 'One size' && !$item ? '' : $v['size']) ?>" placeholder="e.g. M, W32, 10"></label>
  <label class="sell-row"><span>Condition</span>
    <select name="condition_label" required>
      <option value="">Select</option>
      <?php foreach (CONDITIONS as $c => $desc): ?><option value="<?= e($c) ?>" title="<?= e($desc) ?>" <?= $v['condition_label'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
    </select>
  </label>
  <div class="sell-row" style="flex-direction:column;align-items:stretch;padding:14px 18px">
    <span style="margin-bottom:10px">Colors <span class="muted small">(up to 2)</span></span>
    <div class="checks"><?php foreach (COLORS as $c => $hex): ?><label><input type="checkbox" name="colors[]" value="<?= e($c) ?>" <?= in_array($c, $v['colors'], true) ? 'checked' : '' ?>><span class="swatch" style="background:<?= e($hex) ?>"></span><?= e($c) ?></label><?php endforeach; ?></div>
  </div>
  <label class="sell-row"><span>Material <span class="muted small">(recommended)</span></span>
    <select name="material">
      <option value="">Select</option>
      <?php foreach (MATERIALS as $m): ?><option <?= $v['material'] === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?>
    </select>
  </label>

  <h2 class="section-title">Pricing</h2>
  <label class="sell-row"><span>Price</span><input name="price" type="number" step="0.01" min="1" max="10000" inputmode="decimal" value="<?= e($v['price']) ?>" placeholder="$0.00" required></label>
  <p class="hint" style="margin:0 16px">Buyers pay a Buyer Protection fee on top (<?= money(fee_fixed()) ?> + <?= fee_rate() * 100 ?>%). You receive the full price.</p>

  <h2 class="section-title">Shipping</h2>
  <p class="muted" style="margin:-6px 16px 12px">The buyer always pays for shipping</p>
  <?php foreach (PARCEL_SIZES as $k => [$name, $desc]): ?>
    <label class="option">
      <input type="radio" name="parcel_size" value="<?= $k ?>" <?= $v['parcel_size'] === $k ? 'checked' : '' ?> required>
      <div class="opt-main"><div class="opt-title"><?= e($name) ?></div><div class="opt-sub"><?= e($desc) ?></div></div>
      <span class="radio"></span>
    </label>
  <?php endforeach; ?>

  <div class="pad">
    <p class="muted small" style="margin-top:0">By listing, you confirm the item is yours to sell, is described accurately, and isn't on the <a class="link" href="prohibited.php" target="_blank">prohibited items list</a>.</p>
    <button class="btn btn-primary"><?= $item ? 'Save changes' : 'Upload' ?></button>
  </div>
</form>
<?php page_end();
