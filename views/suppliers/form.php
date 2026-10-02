<?php
/** @var string $mode */
/** @var \App\Entity\Supplier|null $target */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $old */

use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];
$isEdit = $mode === 'edit';
$action = $isEdit ? "/suppliers/{$target->id}" : '/suppliers';
$name = $old['name'] ?? ($target->name ?? '');
$contact = $old['contact'] ?? ($target->contact ?? '');
$address = $old['address'] ?? ($target->address ?? '');

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Supplier', 'url' => '/suppliers'],
        ['label' => $isEdit ? 'Edit Supplier' : 'Tambah Supplier'],
    ],
]);
?>
<div class="topbar">
    <h1><?= $isEdit ? 'Edit Supplier' : 'Tambah Supplier' ?></h1>
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
            <label for="contact">Kontak</label>
            <input type="text" id="contact" name="contact" value="<?= View::e($contact) ?>">
        </div>
        <div class="form-field">
            <label for="address">Alamat</label>
            <textarea id="address" name="address" rows="3"><?= View::e($address) ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Simpan</button>
            <a href="/suppliers" class="btn">Batal</a>
        </div>
    </form>
</div>
