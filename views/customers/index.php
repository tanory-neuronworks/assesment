<?php
/** @var string $tableHtml */

use App\Core\View;

echo View::renderFile('components.breadcrumb', ['items' => [['label' => 'Customer']]]);
?>
<div class="topbar">
    <h1>Customer</h1>
    <a href="/customers/create" class="btn btn--primary"><?= \App\Core\Icon::svg('plus', 15) ?> Tambah Customer</a>
</div>

<?= $tableHtml ?>
