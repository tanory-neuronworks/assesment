<?php
/** @var string $tableHtml */
/** @var \App\Entity\User|null $user */

use App\Core\View;
use App\Entity\Role;

$isAdmin = $user?->role === Role::Admin;

echo View::renderFile('components.breadcrumb', ['items' => [['label' => 'Kategori']]]);
?>
<div class="topbar">
    <h1>Kategori</h1>
    <?php if ($isAdmin): ?>
        <a href="/categories/create" class="btn btn--primary"><?= \App\Core\Icon::svg('plus', 15) ?> Tambah Kategori</a>
    <?php endif; ?>
</div>

<?= $tableHtml ?>
