<?php
/** @var \App\Entity\User $u */

use App\Core\View;
?>
<tr>
    <td><?= View::e($u->name) ?></td>
    <td><?= View::e($u->username) ?></td>
    <td><?= View::e($u->email) ?></td>
    <td><?= View::e($u->role->label()) ?></td>
    <td>
        <span class="badge <?= $u->isActive ? 'badge--active' : 'badge--inactive' ?>">
            <?= $u->isActive ? 'Aktif' : 'Nonaktif' ?>
        </span>
    </td>
    <td class="col-actions">
        <?= View::renderFile('components.row-menu', ['items' => [
            ['type' => 'link', 'icon' => 'edit', 'label' => 'Edit', 'href' => "/users/{$u->id}/edit"],
            [
                'type' => 'form',
                'icon' => $u->isActive ? 'x-circle' : 'check-circle',
                'label' => $u->isActive ? 'Nonaktifkan' : 'Aktifkan',
                'action' => "/users/{$u->id}/toggle-active",
                'confirm' => 'Ubah status user ini?',
                'fields' => ['active' => $u->isActive ? '0' : '1'],
                'danger' => $u->isActive,
            ],
        ]]) ?>
    </td>
</tr>
