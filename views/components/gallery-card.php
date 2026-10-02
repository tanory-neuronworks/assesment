<?php
/**
 * A single gallery card. Split out of gallery-results.php so the same
 * markup can be reused by the "Muat Lebih Banyak" AJAX action, which only
 * ever renders the handful of newly-fetched cards, never the whole grid.
 *
 * @var array{id:int,href:string,image:?string,uploadUrl:string,name:string,sku:string,price:string,stock:int,lowStock:bool,isActive:bool} $p
 * @var bool $isAdmin
 */

use App\Core\View;

$menuItems = [
    ['type' => 'link', 'icon' => 'eye', 'label' => 'Detail', 'href' => $p['href']],
];
if ($isAdmin) {
    $menuItems[] = ['type' => 'link', 'icon' => 'edit', 'label' => 'Edit', 'href' => "/products/{$p['id']}/edit"];
    $menuItems[] = [
        'type' => 'form',
        'icon' => $p['isActive'] ? 'x-circle' : 'check-circle',
        'label' => $p['isActive'] ? 'Nonaktifkan' : 'Aktifkan',
        'action' => "/products/{$p['id']}/toggle-active",
        'confirm' => 'Ubah status produk ini?',
        'fields' => ['active' => $p['isActive'] ? '0' : '1'],
        'danger' => $p['isActive'],
    ];
}
?>
<div class="gallery-card">
    <div class="gallery-card__thumb-wrap">
        <?= View::renderFile('components.image-trigger', [
            'src' => $p['image'] ?? '',
            'alt' => $p['name'],
            'imgClass' => 'gallery-card__img',
            'uploadUrl' => $isAdmin ? $p['uploadUrl'] : null,
        ]) ?>
    </div>
    <span
        class="gallery-card__status gallery-card__status--<?= $p['isActive'] ? 'active' : 'inactive' ?>"
        data-label="<?= $p['isActive'] ? 'Aktif' : 'Nonaktif' ?>"
    ></span>
    <div class="gallery-card__menu">
        <?= View::renderFile('components.row-menu', ['items' => $menuItems]) ?>
    </div>
    <div class="gallery-card__body">
        <a href="<?= View::e($p['href']) ?>" class="gallery-card__link">
            <div class="gallery-card__sku"><?= View::e($p['sku']) ?></div>
            <div class="gallery-card__name"><?= View::e($p['name']) ?></div>
        </a>
        <div class="gallery-card__price"><?= View::e($p['price']) ?></div>
        <div class="gallery-card__stock">
            Stok: <?= (int) $p['stock'] ?>
            <?php if ($p['lowStock']): ?>
                <span class="badge badge--low">Low</span>
            <?php endif; ?>
        </div>
    </div>
</div>
