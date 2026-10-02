<?php
/** @var string $tableHtml */

use App\Core\View;

echo View::renderFile('components.breadcrumb', ['items' => [['label' => 'User']]]);
?>
<div class="topbar">
    <h1>Manajemen User</h1>
    <a href="/users/create" class="btn btn--primary"><?= \App\Core\Icon::svg('plus', 15) ?> Tambah User</a>
</div>

<?= $tableHtml ?>
