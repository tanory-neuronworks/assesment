<?php
/** @var string $mode */
/** @var \App\Entity\Category|null $target */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $old */

use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];
$isEdit = $mode === 'edit';
$action = $isEdit ? "/categories/{$target->id}" : '/categories';
$name = $old['name'] ?? ($target->name ?? '');
$description = $old['description'] ?? ($target->description ?? '');

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Kategori', 'url' => '/categories'],
        ['label' => $isEdit ? 'Edit Kategori' : 'Tambah Kategori'],
    ],
]);
?>
<div class="topbar">
    <h1><?= $isEdit ? 'Edit Kategori' : 'Tambah Kategori' ?></h1>
</div>

<div class="card">
    <form method="post" action="<?= View::e($action) ?>" data-validate novalidate>
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="name">Nama</label>
            <input type="text" id="name" name="name" required value="<?= View::e($name) ?>">
            <?php if (isset($errors['name'])): ?><span class="field-error"><?= View::e($errors['name']) ?></span><?php endif; ?>
        </div>
        <div class="form-field">
            <label for="description">Deskripsi</label>
            <textarea id="description" name="description" rows="3"><?= View::e($description) ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Simpan</button>
            <a href="/categories" class="btn">Batal</a>
        </div>
    </form>
</div>
