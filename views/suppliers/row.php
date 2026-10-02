<?php
/** @var \App\Entity\Supplier $s */

use App\Core\View;
?>
<tr>
    <td><?= View::e($s->name) ?></td>
    <td><?= View::e($s->contact) ?></td>
    <td><?= View::e($s->address) ?></td>
    <td>
        <span class="badge <?= $s->isActive ? 'badge--active' : 'badge--inactive' ?>">
            <?= $s->isActive ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </td>
    <td class="col-actions">
        <?= View::renderFile('components.row-menu', ['items' => [
            ['type' => 'link', 'icon' => 'edit', 'label' => 'Edit', 'href' => "/suppliers/{$s->id}/edit"],
            [
                'type' => 'form',
                'icon' => $s->isActive ? 'x-circle' : 'check-circle',
                'label' => $s->isActive ? 'Nonaktifkan' : 'Aktifkan',
                'action' => "/suppliers/{$s->id}/toggle-active",
                'confirm' => 'Ubah status supplier ini?',
                'fields' => ['active' => $s->isActive ? '0' : '1'],
                'danger' => $s->isActive,
            ],
        ]]) ?>
    </td>
</tr>
