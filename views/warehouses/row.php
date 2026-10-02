<?php
/** @var \App\Entity\Warehouse $w */
/** @var bool $isAdmin */

use App\Core\View;
?>
<tr>
    <td><?= View::e($w->name) ?></td>
    <td><?= View::e($w->location) ?></td>
    <td>
        <span class="badge <?= $w->isActive ? 'badge--active' : 'badge--inactive' ?>">
            <?= $w->isActive ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </td>
    <?php if ($isAdmin): ?>
        <td class="col-actions">
            <?= View::renderFile('components.row-menu', ['items' => [
                ['type' => 'link', 'icon' => 'edit', 'label' => 'Edit', 'href' => "/warehouses/{$w->id}/edit"],
                [
                    'type' => 'form',
                    'icon' => $w->isActive ? 'x-circle' : 'check-circle',
                    'label' => $w->isActive ? 'Nonaktifkan' : 'Aktifkan',
                    'action' => "/warehouses/{$w->id}/toggle-active",
                    'confirm' => 'Ubah status gudang ini?',
                    'fields' => ['active' => $w->isActive ? '0' : '1'],
                    'danger' => $w->isActive,
                ],
            ]]) ?>
        </td>
    <?php endif; ?>
</tr>
