<?php
/** @var string $tableHtml */
/** @var \App\Entity\PurchaseOrderStatus[] $statuses */
/** @var array<string,mixed> $filters */
/** @var array<int,array{label:string,value:string}> $boardStats */
/** @var array<int,array<string,mixed>> $boardColumns */
/** @var string $boardSearch */
/** @var string $boardFrom */
/** @var string $boardTo */

use App\Core\Icon;
use App\Core\View;
use App\Entity\PurchaseOrderStatus;

$filters = $filters ?? [];
$defaultView = $defaultView ?? 'board';

echo View::renderFile('components.breadcrumb', ['items' => [['label' => 'Purchase Order']]]);
?>
<div class="topbar">
    <h1>Purchase Order</h1>
    <a href="/purchase-orders/create" class="btn btn--primary"><?= Icon::svg('plus', 15) ?> Buat PO</a>
</div>

<?= View::renderFile('components.view-tabs', [
    'default' => $defaultView,
    'tabs' => [
        ['key' => 'table', 'label' => 'Tabel', 'icon' => 'inbox'],
        ['key' => 'board', 'label' => 'Board', 'icon' => 'dashboard'],
    ],
]) ?>

<div data-view-panel="table" <?= $defaultView === 'table' ? '' : 'hidden' ?>>
    <div class="card">
        <form method="get" action="/purchase-orders" class="filters">
            <input type="hidden" name="q" value="<?= View::e($filters['q'] ?? '') ?>">
            <input type="hidden" name="view" value="table">
            <?= View::renderFile('components.enum-dropdown', [
                'name' => 'status',
                'placeholder' => 'Semua Status',
                'selected' => $filters['status'] ?? '',
                'options' => array_map(
                    static fn (PurchaseOrderStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                    $statuses
                ),
            ]) ?>
            <button type="submit" class="btn btn--sm"><?= Icon::svg('search', 14) ?> Cari</button>
            <a href="/purchase-orders" class="btn btn--sm">Reset</a>
        </form>
    </div>

    <?= $tableHtml ?>
</div>

<div data-view-panel="board" <?= $defaultView === 'board' ? '' : 'hidden' ?>>
    <?= View::renderFile('components.board-view', [
        'stats' => $boardStats,
        'columns' => $boardColumns,
        'emptyMessage' => 'Belum ada order.',
        'searchPlaceholder' => 'Cari PO, supplier, atau produk...',
        'searchValue' => $boardSearch,
        'fromValue' => $boardFrom,
        'toValue' => $boardTo,
        'searchAction' => '/purchase-orders',
    ]) ?>
</div>
