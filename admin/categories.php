<?php
require __DIR__ . '/_inc.php';
require_admin();

if (is_post()) {
    check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $cats = categories();
    if ($act === 'add') {
        $parent = (int)($_POST['parent'] ?? 0);
        if ($name === '' || mb_strlen($name) > 80) flash('Enter a name (max 80 characters).', 'err');
        elseif ($parent && !isset($cats[$parent])) flash('Parent not found.', 'err');
        else {
            $sort = (int)q('SELECT COALESCE(MAX(sort), -1) + 1 FROM categories WHERE parent_id <=> ?', [$parent ?: null])->fetchColumn();
            q('INSERT INTO categories (parent_id, name, icon, sort) VALUES (?, ?, ?, ?)', [$parent ?: null, $name, $parent ? null : 'tag', $sort]);
            $newId = (int)db()->lastInsertId();
            admin_log('add_category', 'category', $newId, ($parent ? category_path_label($parent) . ' › ' : '') . $name);
            flash('Category added.');
        }
    } elseif ($act === 'rename' && isset($cats[$id])) {
        if ($name === '' || mb_strlen($name) > 80) flash('Enter a name (max 80 characters).', 'err');
        else {
            q('UPDATE categories SET name = ? WHERE id = ?', [$name, $id]);
            admin_log('rename_category', 'category', $id, $cats[$id]['name'] . ' → ' . $name);
            flash('Category renamed.');
        }
    } elseif ($act === 'move' && isset($cats[$id])) {
        $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
        $sibs = category_children($cats[$id]['parent_id'] === null ? null : (int)$cats[$id]['parent_id']);
        $pos = array_search($id, array_map(function ($c) { return (int)$c['id']; }, $sibs), true);
        $swap = $sibs[$pos + $dir] ?? null;
        if ($swap) {
            // Re-number siblings so the swap is stable.
            foreach ($sibs as $n => $c) q('UPDATE categories SET sort = ? WHERE id = ?', [$n, $c['id']]);
            q('UPDATE categories SET sort = ? WHERE id = ?', [$pos + $dir, $id]);
            q('UPDATE categories SET sort = ? WHERE id = ?', [$pos, $swap['id']]);
        }
    } elseif ($act === 'delete' && isset($cats[$id])) {
        $ids = category_descendants($id);
        $used = (int)q('SELECT COUNT(*) FROM items WHERE category_id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchColumn();
        if ($used) flash("Can't delete: $used listing" . ($used === 1 ? '' : 's') . ' use this category or its subcategories. Move or remove them first.', 'err');
        else {
            $label = category_path_label($id);
            q('DELETE FROM categories WHERE id = ?', [$id]);
            admin_log('delete_category', 'category', $id, $label);
            flash('Category deleted.');
        }
    }
    redirect('categories.php');
}

$counts = q("SELECT category_id, COUNT(*) FROM items WHERE status IN ('active','reserved') GROUP BY category_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$subtotal = function (int $id) use ($counts) {
    $n = 0;
    foreach (category_descendants($id) as $c) $n += (int)($counts[$c] ?? 0);
    return $n;
};

function render_tree(?int $parent, callable $subtotal, int $depth = 0): void
{
    $kids = category_children($parent);
    if (!$kids) return;
    echo $depth ? '<ul>' : '<ul class="tree">';
    $last = count($kids) - 1;
    foreach ($kids as $n => $c) {
        $id = (int)$c['id'];
        $hasKids = (bool)category_children($id);
        echo '<li>';
        if ($hasKids) echo '<details' . ($depth === 0 ? '' : '') . '><summary>';
        echo '<div class="node">' . ($hasKids ? icon('chevron', 16) : '<span style="width:16px"></span>')
           . '<span>' . e($c['name']) . '</span><span class="cnt">' . $subtotal($id) . ' live</span><span class="actions">';
        echo '<form method="post" class="inline-form" onsubmit="var n=prompt(\'Rename category\', ' . e(json_encode($c['name'])) . '); if(!n) return false; this.name.value=n;">'
           . csrf_field() . '<input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="name"><button class="btn btn-xs">Rename</button></form>';
        echo '<form method="post" class="inline-form" onsubmit="var n=prompt(\'New subcategory under ' . e(addslashes($c['name'])) . '\'); if(!n) return false; this.name.value=n;">'
           . csrf_field() . '<input type="hidden" name="action" value="add"><input type="hidden" name="parent" value="' . $id . '"><input type="hidden" name="name"><button class="btn btn-xs">+ Sub</button></form>';
        foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow) {
            if (($dir === 'up' && $n === 0) || ($dir === 'down' && $n === $last)) continue;
            echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="move"><input type="hidden" name="dir" value="' . $dir . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-xs" aria-label="Move ' . $dir . '">' . $arrow . '</button></form>';
        }
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-xs btn-danger" data-confirm="Delete ' . e($c['name']) . ' and all its subcategories?">Delete</button></form>';
        echo '</span></div>';
        if ($hasKids) { echo '</summary>'; render_tree($id, $subtotal, $depth + 1); echo '</details>'; }
        echo '</li>';
    }
    echo '</ul>';
}

admin_start('Categories', 'categories');
?>
<div class="cols-2-1">
  <section class="panel">
    <h2>Category tree</h2>
    <p class="panel-sub">Click a category to expand it. Hover a row for actions. Counts are live listings including subcategories.</p>
    <?php render_tree(null, $subtotal); ?>
  </section>
  <section class="panel">
    <h2>Add top-level category</h2>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="parent" value="0">
      <input class="input" name="name" maxlength="80" placeholder="e.g. Pets" required aria-label="Category name">
      <button class="btn btn-primary btn-sm">Add</button>
    </form>
    <p class="note mt-16">Sellers can only list in the deepest level of a branch. Categories that contain listings can't be deleted.</p>
  </section>
</div>
<?php admin_end();
