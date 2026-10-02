<?php
/**
 * "Item Produk" picker + cart shared by the purchase and sales order forms.
 * order-items.js reads the data-* hooks below.
 *
 * @var array<string,string> $errors
 * @var string $qtyField   name of the posted qty field (items[n][$qtyField])
 * @var string $priceField name of the posted unit price field
 * @var string $priceLabel label of the unit price input
 */

use App\Core\Icon;
use App\Core\View;

$errors = $errors ?? [];
?>
<h2 class="section-title">Item Produk</h2>
<?php if (isset($errors['items'])): ?><p class="field-error"><?= View::e($errors['items']) ?></p><?php endif; ?>

<div class="line-item" data-item-picker data-qty-field="<?= View::e($qtyField) ?>" data-price-field="<?= View::e($priceField) ?>">
    <label class="line-item__field line-item__field--product" for="item-picker-product">
        <span>Produk</span>
        <?= View::renderFile('components.async-dropdown', [
            'type' => 'products',
            'name' => '_item_picker_product_id',
            'inputId' => 'item-picker-product',
            'placeholder' => 'Cari produk (nama/SKU)...',
            'required' => false,
        ]) ?>
    </label>
    <label class="line-item__field line-item__field--qty">
        <span>Qty</span>
        <input type="number" min="1" step="1" value="1" data-item-picker-qty>
    </label>
    <label class="line-item__field line-item__field--price">
        <span><?= View::e($priceLabel) ?></span>
        <input type="number" min="0" step="0.01" data-item-picker-price data-<?= View::e(str_replace('_', '-', $priceField)) ?>-input>
    </label>
    <button type="button" class="btn btn--primary" data-add-to-cart>
        <?= Icon::svg('plus', 14) ?> Tambah
    </button>
</div>
<p class="field-error" data-item-picker-error hidden></p>

<div class="cart-gallery" data-cart-items>
    <p class="cart-gallery__empty" data-cart-empty>Belum ada item ditambahkan.</p>
</div>

<template data-cart-card-template>
    <div class="cart-card" data-cart-card>
        <button type="button" class="cart-card__remove" data-remove-cart-item aria-label="Hapus item">
            <?= Icon::svg('x-circle', 16) ?>
        </button>
        <div class="cart-card__thumb-wrap">
            <img class="cart-card__img" data-cart-card-img alt="" hidden>
            <span class="cart-card__img image-trigger__empty" data-cart-card-empty-icon><?= Icon::svg('box', 20) ?></span>
        </div>
        <div class="cart-card__body">
            <div class="cart-card__label" data-cart-card-label></div>
            <div class="cart-card__meta">
                <span data-cart-card-qty></span> x <span data-cart-card-price></span>
            </div>
            <div class="cart-card__subtotal" data-cart-card-subtotal></div>
        </div>
    </div>
</template>
