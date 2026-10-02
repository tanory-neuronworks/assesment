<?php
/** @var \App\Entity\Warehouse[] $warehouses */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $old */

use App\Core\Csrf;
use App\Core\View;

$errors = $errors ?? [];
$old = $old ?? [];

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Purchase Order', 'url' => '/purchase-orders'],
        ['label' => 'Buat PO'],
    ],
]);
?>
<div class="topbar">
    <h1>Buat Purchase Order</h1>
</div>

<div class="card">
    <form method="post" action="/purchase-orders" data-validate novalidate>
        <?= Csrf::field() ?>
        <div class="form-grid">
            <div class="form-field">
                <label for="supplier_id">Supplier</label>
                <?= View::renderFile('components.async-dropdown', [
                    'type' => 'suppliers',
                    'name' => 'supplier_id',
                    'placeholder' => 'Cari supplier...',
                ]) ?>
                <?php if (isset($errors['supplier_id'])): ?><span class="field-error"><?= View::e($errors['supplier_id']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="warehouse_id">Gudang Tujuan</label>
                <?= View::renderFile('components.enum-dropdown', [
                    'name' => 'warehouse_id',
                    'placeholder' => 'Pilih gudang',
                    'selected' => $old['warehouse_id'] ?? '',
                    'required' => true,
                    'options' => array_map(
                        static fn ($w) => ['value' => (string) $w->id, 'label' => $w->name],
                        $warehouses
                    ),
                ]) ?>
                <?php if (isset($errors['warehouse_id'])): ?><span class="field-error"><?= View::e($errors['warehouse_id']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="order_date">Tanggal Order</label>
                <input type="date" id="order_date" name="order_date" required value="<?= View::e($old['order_date'] ?? date('Y-m-d')) ?>">
                <?php if (isset($errors['order_date'])): ?><span class="field-error"><?= View::e($errors['order_date']) ?></span><?php endif; ?>
            </div>
        </div>

        <?= View::renderFile('components.order-item-picker', [
            'errors' => $errors,
            'qtyField' => 'qty_ordered',
            'priceField' => 'cost_price',
            'priceLabel' => 'Harga Beli',
        ]) ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Simpan PO</button>
            <a href="/purchase-orders" class="btn">Batal</a>
        </div>
    </form>
</div>

<script src="/assets/js/order-items.js"></script>
