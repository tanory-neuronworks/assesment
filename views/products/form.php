<?php
/** @var string $mode */
/** @var \App\Entity\Product|null $target */
/** @var \App\Entity\Category[] $categories */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $old */

use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];
$isEdit = $mode === 'edit';
$action = $isEdit ? "/products/{$target->id}" : '/products';
$sku = $old['sku'] ?? ($target->sku ?? '');
$name = $old['name'] ?? ($target->name ?? '');
$categoryId = $old['category_id'] ?? ($target->categoryId ?? '');
$unit = $old['unit'] ?? ($target->unit ?? '');
$costPrice = $old['cost_price'] ?? ($target->costPrice ?? '');
$sellPrice = $old['sell_price'] ?? ($target->sellPrice ?? '');
$reorderPoint = $old['reorder_point'] ?? ($target->reorderPoint ?? '0');

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Produk', 'url' => '/products'],
        ['label' => $isEdit ? 'Edit Produk' : 'Tambah Produk'],
    ],
]);
?>
<div class="topbar">
    <h1><?= $isEdit ? 'Edit Produk' : 'Tambah Produk' ?></h1>
</div>

<div class="card">
    <form method="post" action="<?= View::e($action) ?>" enctype="multipart/form-data" data-validate novalidate>
        <?= Csrf::field() ?>
        <div class="form-grid">
            <div class="form-field">
                <label for="sku">SKU</label>
                <input type="text" id="sku" name="sku" required value="<?= View::e($sku) ?>">
                <?php if (isset($errors['sku'])): ?><span class="field-error"><?= View::e($errors['sku']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="name">Nama Produk</label>
                <input type="text" id="name" name="name" required value="<?= View::e($name) ?>">
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= View::e($errors['name']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="category_id">Kategori</label>
                <?= View::renderFile('components.enum-dropdown', [
                    'name' => 'category_id',
                    'placeholder' => 'Pilih kategori',
                    'selected' => (string) $categoryId,
                    'required' => true,
                    'options' => array_map(
                        static fn ($c) => ['value' => (string) $c->id, 'label' => $c->name],
                        $categories
                    ),
                ]) ?>
                <?php if (isset($errors['category_id'])): ?><span class="field-error"><?= View::e($errors['category_id']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="unit">Unit</label>
                <input type="text" id="unit" name="unit" required placeholder="pcs, box, dsb" value="<?= View::e($unit) ?>">
                <?php if (isset($errors['unit'])): ?><span class="field-error"><?= View::e($errors['unit']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="cost_price">Harga Beli</label>
                <input type="number" id="cost_price" name="cost_price" min="0" step="0.01" required value="<?= View::e((string) $costPrice) ?>">
                <?php if (isset($errors['cost_price'])): ?><span class="field-error"><?= View::e($errors['cost_price']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="sell_price">Harga Jual</label>
                <input type="number" id="sell_price" name="sell_price" min="0" step="0.01" required value="<?= View::e((string) $sellPrice) ?>">
                <?php if (isset($errors['sell_price'])): ?><span class="field-error"><?= View::e($errors['sell_price']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="reorder_point">Reorder Point</label>
                <input type="number" id="reorder_point" name="reorder_point" min="0" step="1" required value="<?= View::e((string) $reorderPoint) ?>">
                <?php if (isset($errors['reorder_point'])): ?><span class="field-error"><?= View::e($errors['reorder_point']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="image">Gambar (opsional, JPG/PNG/WEBP maks 2MB)</label>
                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
                <?php if (isset($errors['image'])): ?><span class="field-error"><?= View::e($errors['image']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Simpan</button>
            <a href="/products" class="btn">Batal</a>
        </div>
    </form>
</div>
