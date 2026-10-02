<?php
/** @var string $mode */
/** @var \App\Entity\Warehouse|null $target */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $old */

use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];
$isEdit = $mode === 'edit';
$action = $isEdit ? "/warehouses/{$target->id}" : '/warehouses';
$name = $old['name'] ?? ($target->name ?? '');
$location = $old['location'] ?? ($target->location ?? '');

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Gudang', 'url' => '/warehouses'],
        ['label' => $isEdit ? 'Edit Gudang' : 'Tambah Gudang'],
    ],
]);
?>
<div class="topbar">
    <h1><?= $isEdit ? 'Edit Gudang' : 'Tambah Gudang' ?></h1>
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
            <label for="location">Lokasi</label>
            <input type="text" id="location" name="location" required value="<?= View::e($location) ?>">
            <?php if (isset($errors['location'])): ?><span class="field-error"><?= View::e($errors['location']) ?></span><?php endif; ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Simpan</button>
            <a href="/warehouses" class="btn">Batal</a>
        </div>
    </form>
</div>
