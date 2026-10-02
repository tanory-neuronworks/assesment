<?php
/** @var \App\Entity\Product $p */
/** @var int $stock */
/** @var bool $isAdmin */

use App\Core\View;

$menuItems = [
    ['type' => 'link', 'icon' => 'eye', 'label' => 'Detail', 'href' => "/products/{$p->id}"],
];
if ($isAdmin) {
    $menuItems[] = ['type' => 'link', 'icon' => 'edit', 'label' => 'Edit', 'href' => "/products/{$p->id}/edit"];
    $menuItems[] = [
        'type' => 'form',
        'icon' => $p->isActive ? 'x-circle' : 'check-circle',
        'label' => $p->isActive ? 'Nonaktifkan' : 'Aktifkan',
        'action' => "/products/{$p->id}/toggle-active",
        'confirm' => 'Ubah status produk ini?',
        'fields' => ['active' => $p->isActive ? '0' : '1'],
        'danger' => $p->isActive,
    ];
}
?>
<tr>
    <td class="col-thumbnail">
        <?= View::renderFile('components.image-trigger', [
            'src' => $p->image ?? '',
            'alt' => $p->name,
            'imgClass' => 'table-thumbnail',
            'uploadUrl' => $isAdmin ? "/products/{$p->id}/image" : null,
        ]) ?>
    </td>
    <td><?= View::e($p->sku) ?></td>
    <td><?= View::e($p->name) ?></td>
    <td><?= View::e($p->categoryName ?? '-') ?></td>
    <td style="text-align: right;"><?= number_format($p->sellPrice, 2) ?></td>
    <td style="text-align: right;">
        <?= $stock ?>
        <?php if ($stock <= $p->reorderPoint): ?>
            <span class="badge badge--low">Low</span>
        <?php endif; ?>
    </td>
    <td>
        <span class="badge <?= $p->isActive ? 'badge--active' : 'badge--inactive' ?>">
            <?= $p->isActive ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </td>
    <td class="col-actions">
        <?= View::renderFile('components.row-menu', ['items' => $menuItems]) ?>
    </td>
</tr>
