<?php
/** @var \App\Entity\PurchaseOrder $po */
/** @var \App\Entity\User|null $user */

use App\Core\Csrf;
use App\Core\View;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;

$isAdmin = $user?->role === Role::Admin;
$canReceive = in_array($po->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true);
$canCancel = $canReceive;
$badgeClass = match ($po->status) {
    PurchaseOrderStatus::Received => 'badge--active',
    PurchaseOrderStatus::Cancelled => 'badge--inactive',
    default => 'badge--low',
};

$total = 0;
foreach ($po->items as $item) {
    $total += $item->qtyOrdered * $item->costPrice;
}

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Purchase Order', 'url' => '/purchase-orders'],
        ['label' => 'PO-' . str_pad((string) $po->id, 5, '0', STR_PAD_LEFT)],
    ],
]);
?>
<div class="topbar">
    <h1>PO-<?= str_pad((string) $po->id, 5, '0', STR_PAD_LEFT) ?></h1>
    <a href="/purchase-orders" class="btn">Kembali</a>
</div>

<div class="card">
    <p><strong>Supplier:</strong> <?= View::e($po->supplierName ?? '-') ?></p>
    <p><strong>Gudang Tujuan:</strong> <?= View::e($po->warehouseName ?? '-') ?></p>
    <p><strong>Tanggal Order:</strong> <?= View::e($po->orderDate) ?></p>
    <p><strong>Dibuat Oleh:</strong> <?= View::e($po->createdByName ?? '-') ?></p>
    <p><strong>Status:</strong> <span class="badge <?= $badgeClass ?>"><?= View::e($po->status->label()) ?></span></p>

    <?php if ($canCancel): ?>
        <form method="post" action="/purchase-orders/<?= (int) $po->id ?>/cancel" data-confirm="Batalkan PO ini?">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--danger btn--sm">Batalkan PO</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0; font-size:1rem;">Item &amp; Penerimaan Barang</h2>
    <form method="post" action="/purchase-orders/<?= (int) $po->id ?>/receive">
        <?= Csrf::field() ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Foto</th>
                    <th>SKU</th>
                    <th>Produk</th>
                    <th style="text-align: right;">Qty Order</th>
                    <th style="text-align: right;">Sudah Diterima</th>
                    <th style="text-align: right;">Sisa</th>
                    <th style="text-align: right;">Harga Beli</th>
                    <th style="text-align: right;">Subtotal</th>
                    <?php if ($canReceive): ?><th>Terima Sekarang</th><?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($po->items as $item): ?>
                    <tr>
                        <td>
                            <?= View::renderFile('components.image-trigger', [
                                'src' => $item->productImage ?? '',
                                'alt' => $item->productName ?? '',
                                'imgClass' => 'table-thumbnail',
                                'uploadUrl' => $isAdmin ? "/products/{$item->productId}/image" : null,
                            ]) ?>
                        </td>
                        <td><?= View::e($item->productSku ?? '-') ?></td>
                        <td><?= View::e($item->productName ?? '-') ?></td>
                        <td style="text-align: right;"><?= (int) $item->qtyOrdered ?> <?= View::e($item->unit ?? '') ?></td>
                        <td style="text-align: right;"><?= (int) $item->qtyReceived ?></td>
                        <td style="text-align: right;"><?= (int) $item->remaining() ?></td>
                        <td style="text-align: right;"><?= number_format($item->costPrice, 2) ?></td>
                        <td style="text-align: right;"><?= number_format($item->qtyOrdered * $item->costPrice, 2) ?></td>
                        <?php if ($canReceive): ?>
                            <td>
                                <?php if ($item->remaining() > 0): ?>
                                    <input type="number" aria-label="Jumlah diterima" name="receive[<?= (int) $item->id ?>]" min="0" max="<?= (int) $item->remaining() ?>" step="1" value="0" style="width:6rem;">
                                <?php else: ?>
                                    <span class="badge badge--active">Lengkap</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="7" style="text-align:right;"><strong>Total</strong></td>
                    <td style="text-align: right;"><strong><?= number_format($total, 2) ?></strong></td>
                    <?php if ($canReceive): ?><td></td><?php endif; ?>
                </tr>
                </tfoot>
            </table>
        </div>
        <?php if ($canReceive): ?>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Catat Penerimaan</button>
            </div>
        <?php endif; ?>
    </form>
</div>
