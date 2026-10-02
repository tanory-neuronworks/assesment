<?php
/** @var \App\Entity\Product $product */
/** @var \App\Entity\ProductStock[] $stocks */
/** @var \App\Entity\StockLedgerEntry[] $ledger */
/** @var \App\Entity\User|null $user */

use App\Core\View;
use App\Entity\Role;
use App\Entity\StockMovementType;

$isAdmin = $user?->role === Role::Admin;

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Produk', 'url' => '/products'],
        ['label' => $product->name],
    ],
]);
?>
<div class="topbar">
    <h1><?= View::e($product->name) ?></h1>
    <a href="/products" class="btn">Kembali</a>
</div>

<div class="card product-detail">
    <div class="product-detail__info">
        <p><strong>SKU:</strong> <?= View::e($product->sku) ?></p>
        <p><strong>Kategori:</strong> <?= View::e($product->categoryName ?? '-') ?></p>
        <p><strong>Unit:</strong> <?= View::e($product->unit) ?></p>
        <p><strong>Harga Beli:</strong> <?= number_format($product->costPrice, 2) ?></p>
        <p><strong>Harga Jual:</strong> <?= number_format($product->sellPrice, 2) ?></p>
        <p><strong>Reorder Point:</strong> <?= (int) $product->reorderPoint ?></p>
    </div>
    <?= View::renderFile('components.image-trigger', [
        'src' => $product->image ?? '',
        'alt' => $product->name,
        'imgClass' => 'product-detail__image',
        'uploadUrl' => $isAdmin ? "/products/{$product->id}/image" : null,
    ]) ?>
</div>

<div class="card">
    <h2 style="margin-top:0; font-size:1rem;">Stok per Gudang</h2>
    <?php if ($stocks === []): ?>
        <div class="empty-state">Belum ada data stok untuk produk ini.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Gudang</th>
                    <th style="text-align: right;">Quantity</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($stocks as $s): ?>
                    <tr>
                        <td><?= View::e($s->warehouseName ?? '-') ?></td>
                        <td style="text-align: right;"><?= (int) $s->quantity ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0; font-size:1rem;">Riwayat Pergerakan Stok Terbaru</h2>
    <?php if ($ledger === []): ?>
        <div class="empty-state">Belum ada pergerakan stok untuk produk ini.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Tipe</th>
                    <th>Gudang</th>
                    <th style="text-align: right;">Qty</th>
                    <th>Oleh</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ledger as $entry): ?>
                    <?php
                    $movementLabel = match ($entry->movementType) {
                        StockMovementType::Receipt => 'Masuk',
                        StockMovementType::Issue => 'Keluar',
                        default => 'Penyesuaian',
                    };
                    ?>
                    <tr>
                        <td><?= View::e($entry->createdAt ?? '-') ?></td>
                        <td>
                            <span class="badge <?= $entry->movementType === StockMovementType::Issue ? 'badge--low' : 'badge--active' ?>">
                                <?= View::e($movementLabel) ?>
                            </span>
                        </td>
                        <td><?= View::e($entry->warehouseName ?? '-') ?></td>
                        <td style="text-align: right;"><?= (int) $entry->quantity ?></td>
                        <td><?= View::e($entry->performedByName ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
