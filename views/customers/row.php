<?php
/** @var \App\Entity\Customer $c */

use App\Core\View;
?>
<tr>
    <td><?= View::e($c->name) ?></td>
    <td><?= View::e($c->contact) ?></td>
    <td><?= View::e($c->address) ?></td>
    <td>
        <span class="badge <?= $c->isActive ? 'badge--active' : 'badge--inactive' ?>">
            <?= $c->isActive ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </td>
    <td class="col-actions">
        <?= View::renderFile('components.row-menu', ['items' => [
            ['type' => 'link', 'icon' => 'edit', 'label' => 'Edit', 'href' => "/customers/{$c->id}/edit"],
            [
                'type' => 'form',
                'icon' => $c->isActive ? 'x-circle' : 'check-circle',
                'label' => $c->isActive ? 'Nonaktifkan' : 'Aktifkan',
                'action' => "/customers/{$c->id}/toggle-active",
                'confirm' => 'Ubah status customer ini?',
                'fields' => ['active' => $c->isActive ? '0' : '1'],
                'danger' => $c->isActive,
            ],
        ]]) ?>
    </td>
</tr>
