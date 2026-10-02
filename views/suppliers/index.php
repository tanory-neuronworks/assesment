<?php
/** @var string $tableHtml */

use App\Core\View;

echo View::renderFile('components.breadcrumb', ['items' => [['label' => 'Supplier']]]);
?>
<div class="topbar">
    <h1>Supplier</h1>
    <a href="/suppliers/create" class="btn btn--primary"><?= \App\Core\Icon::svg('plus', 15) ?> Tambah Supplier</a>
</div>

<?= $tableHtml ?>
