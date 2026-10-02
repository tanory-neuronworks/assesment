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
        ['label' => 'Sales Order', 'url' => '/sales-orders'],
        ['label' => 'Buat SO'],
    ],
]);
?>
<div class="topbar">
    <h1>Buat Sales Order</h1>
</div>

<div class="card">
    <form method="post" action="/sales-orders" data-validate novalidate>
        <?= Csrf::field() ?>
        <div class="form-grid">
            <div class="form-field">
                <label for="customer_id">Customer</label>
                <?= View::renderFile('components.async-dropdown', [
                    'type' => 'customers',
                    'name' => 'customer_id',
                    'placeholder' => 'Cari customer...',
                ]) ?>
                <?php if (isset($errors['customer_id'])): ?><span class="field-error"><?= View::e($errors['customer_id']) ?></span><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="warehouse_id">Gudang Asal</label>
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
            'qtyField' => 'qty',
            'priceField' => 'sell_price',
            'priceLabel' => 'Harga Jual',
        ]) ?>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Simpan sebagai Draft</button>
            <a href="/sales-orders" class="btn">Batal</a>
        </div>
    </form>
</div>

<script src="/assets/js/order-items.js"></script>
