<?php
/** @var \App\Entity\User $user */
/** @var array<string,mixed> $stats */

use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Entity\SalesOrderStatus;
use App\Core\View;

$stats = $stats ?? [];
?>
<div class="topbar">
    <h1>Halo, <?= View::e($user->name) ?></h1>
</div>

<?php if ($user->role === Role::Admin): ?>
    <div class="stat-grid" style="margin-bottom:1.25rem;">
        <div class="stat-card">
            <div class="stat-card__value"><?= View::rupiahShort((float) ($stats['inventoryValue'] ?? 0)) ?></div>
            <div class="stat-card__label">Nilai Inventori</div>
        </div>
        <div class="stat-card">
            <div class="stat-card__value"><?= (int) ($stats['lowStockCount'] ?? 0) ?></div>
            <div class="stat-card__label">Produk di Bawah Reorder Point</div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0; font-size:1rem;">Purchase Order per Status</h2>
        <div class="stat-grid">
            <?php foreach (PurchaseOrderStatus::cases() as $s): ?>
                <div class="stat-card">
                    <div class="stat-card__value"><?= (int) (($stats['poByStatus'] ?? [])[$s->value] ?? 0) ?></div>
                    <div class="stat-card__label"><?= View::e($s->label()) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0; font-size:1rem;">Sales Order per Status</h2>
        <div class="stat-grid">
            <?php foreach (SalesOrderStatus::cases() as $s): ?>
                <div class="stat-card">
                    <div class="stat-card__value"><?= (int) (($stats['soByStatus'] ?? [])[$s->value] ?? 0) ?></div>
                    <div class="stat-card__label"><?= View::e($s->label()) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0; font-size:1rem;">Laporan</h2>
        <?= View::renderFile('components.quick-access', ['items' => [
            ['href' => '/reports/stock-ledger', 'icon' => 'inventory', 'label' => 'Ekspor Pergerakan Stok', 'description' => 'Unduh riwayat keluar-masuk stok (CSV)', 'color' => 'green'],
            ['href' => '/reports/orders', 'icon' => 'send', 'label' => 'Ekspor Status Order', 'description' => 'Unduh status PO & SO (CSV)', 'color' => 'purple'],
        ]]) ?>
    </div>
<?php elseif ($user->role === Role::Sales): ?>
    <div class="card">
        <h2 style="margin-top:0; font-size:1rem;">Order Saya per Status</h2>
        <div class="stat-grid">
            <?php foreach (SalesOrderStatus::cases() as $s): ?>
                <div class="stat-card">
                    <div class="stat-card__value"><?= (int) (($stats['soByStatus'] ?? [])[$s->value] ?? 0) ?></div>
                    <div class="stat-card__label"><?= View::e($s->label()) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0; font-size:1rem;">Laporan</h2>
        <?= View::renderFile('components.quick-access', ['items' => [
            ['href' => '/reports/orders', 'icon' => 'send', 'label' => 'Ekspor Order Saya', 'description' => 'Unduh status SO Anda (CSV)', 'color' => 'purple'],
        ]]) ?>
    </div>
<?php else: ?>
    <div class="stat-grid" style="margin-bottom:1.25rem;">
        <div class="stat-card">
            <div class="stat-card__value"><?= (int) ($stats['pendingReceipts'] ?? 0) ?></div>
            <div class="stat-card__label">PO Menunggu Penerimaan</div>
        </div>
        <div class="stat-card">
            <div class="stat-card__value"><?= (int) ($stats['pendingIssues'] ?? 0) ?></div>
            <div class="stat-card__label">SO Menunggu Goods Issue</div>
        </div>
        <div class="stat-card">
            <div class="stat-card__value"><?= (int) ($stats['lowStockCount'] ?? 0) ?></div>
            <div class="stat-card__label">Produk Low Stock</div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0; font-size:1rem;">Laporan</h2>
        <?= View::renderFile('components.quick-access', ['items' => [
            ['href' => '/reports/stock-ledger', 'icon' => 'inventory', 'label' => 'Ekspor Laporan Stok', 'description' => 'Unduh riwayat keluar-masuk stok (CSV)', 'color' => 'green'],
        ]]) ?>
    </div>
<?php endif; ?>

<?php
$quickAccess = [
    ['href' => '/products', 'icon' => 'box', 'label' => 'Produk & Stok', 'description' => 'Kelola produk dan pantau stok gudang', 'color' => 'blue'],
    ['href' => '/sales-orders', 'icon' => 'cart', 'label' => 'Sales Order', 'description' => 'Buat dan kelola pesanan penjualan', 'color' => 'yellow'],
];
if ($user->role === Role::Admin || $user->role === Role::WarehouseStaff) {
    $quickAccess[] = ['href' => '/purchase-orders', 'icon' => 'truck', 'label' => 'Purchase Order', 'description' => 'Kelola pemesanan ke supplier', 'color' => 'pink'];
}
$quickAccess[] = ['href' => '/categories', 'icon' => 'tag', 'label' => 'Kategori', 'description' => 'Atur kategori produk', 'color' => 'green'];
$quickAccess[] = ['href' => '/warehouses', 'icon' => 'warehouse', 'label' => 'Gudang', 'description' => 'Kelola data gudang', 'color' => 'purple'];
if ($user->role === Role::Admin) {
    $quickAccess[] = ['href' => '/suppliers', 'icon' => 'truck', 'label' => 'Supplier', 'description' => 'Kelola data supplier', 'color' => 'blue'];
    $quickAccess[] = ['href' => '/customers', 'icon' => 'users', 'label' => 'Customer', 'description' => 'Kelola data pelanggan', 'color' => 'yellow'];
    $quickAccess[] = ['href' => '/users', 'icon' => 'shield', 'label' => 'Manajemen User', 'description' => 'Kelola akun dan hak akses', 'color' => 'pink'];
}
?>
<div class="card">
    <h2 style="margin-top:0; font-size:1rem;">Akses cepat</h2>
    <?= View::renderFile('components.quick-access', ['items' => $quickAccess]) ?>
</div>
