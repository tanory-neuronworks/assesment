<?php
/** @var \App\Entity\SalesOrder $so */
/** @var \App\Entity\User|null $user */

use App\Core\Csrf;
use App\Core\View;
use App\Entity\Role;
use App\Entity\SalesOrderStatus;

$badgeClass = match ($so->status) {
    SalesOrderStatus::Fulfilled => 'badge--active',
    SalesOrderStatus::Cancelled => 'badge--inactive',
    default => 'badge--low',
};

$isOwner = $user !== null && $user->id === $so->createdBy;
$isAdmin = $user !== null && $user->role === Role::Admin;
$isWarehouse = $user !== null && $user->role === Role::WarehouseStaff;

$canSubmit = $so->status === SalesOrderStatus::Draft && ($isAdmin || $isOwner);
$canApprove = $so->status === SalesOrderStatus::PendingApproval && $isAdmin && !$isOwner;
$canCancel = in_array($so->status, [SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval, SalesOrderStatus::Approved], true)
    && ($isAdmin || $isOwner);
$canIssue = $so->status === SalesOrderStatus::Approved && ($isAdmin || $isWarehouse);

$total = 0;
foreach ($so->items as $item) {
    $total += $item->qty * $item->sellPrice;
}

echo View::renderFile('components.breadcrumb', [
    'items' => [
        ['label' => 'Sales Order', 'url' => '/sales-orders'],
        ['label' => 'SO-' . str_pad((string) $so->id, 5, '0', STR_PAD_LEFT)],
    ],
]);
?>
<div class="topbar">
    <h1>SO-<?= str_pad((string) $so->id, 5, '0', STR_PAD_LEFT) ?></h1>
    <a href="/sales-orders" class="btn">Kembali</a>
</div>

<div class="card">
    <p><strong>Customer:</strong> <?= View::e($so->customerName ?? '-') ?></p>
    <p><strong>Gudang Asal:</strong> <?= View::e($so->warehouseName ?? '-') ?></p>
    <p><strong>Tanggal Order:</strong> <?= View::e($so->orderDate) ?></p>
    <p><strong>Dibuat Oleh:</strong> <?= View::e($so->createdByName ?? '-') ?></p>
    <p><strong>Disetujui Oleh:</strong> <?= View::e($so->approvedByName ?? '-') ?></p>
    <p><strong>Status:</strong> <span class="badge <?= $badgeClass ?>"><?= View::e($so->status->label()) ?></span></p>

    <?php if ($so->status === SalesOrderStatus::PendingApproval && $isAdmin && $isOwner): ?>
        <p class="field-error">Anda pembuat SO ini — tidak bisa menyetujui/menolak order milik sendiri.</p>
    <?php endif; ?>

    <div class="form-actions">
        <?php if ($canSubmit): ?>
            <form method="post" action="/sales-orders/<?= (int) $so->id ?>/submit" data-confirm="Ajukan SO ini untuk persetujuan?">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary btn--sm">Ajukan</button>
            </form>
        <?php endif; ?>

        <?php if ($canApprove): ?>
            <form method="post" action="/sales-orders/<?= (int) $so->id ?>/approve" data-confirm="Setujui SO ini?">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary btn--sm">Setujui</button>
            </form>
            <form method="post" action="/sales-orders/<?= (int) $so->id ?>/reject" data-confirm="Tolak SO ini?">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger btn--sm">Tolak</button>
            </form>
        <?php endif; ?>

        <?php if ($canIssue): ?>
            <form method="post" action="/sales-orders/<?= (int) $so->id ?>/issue" data-confirm="Catat goods issue untuk SO ini? Stok akan berkurang.">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--primary btn--sm">Catat Goods Issue</button>
            </form>
        <?php endif; ?>

        <?php if ($canCancel): ?>
            <form method="post" action="/sales-orders/<?= (int) $so->id ?>/cancel" data-confirm="Batalkan SO ini?">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn--danger btn--sm">Batalkan</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0; font-size:1rem;">Item</h2>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Foto</th>
                <th>SKU</th>
                <th>Produk</th>
                <th style="text-align: right;">Qty</th>
                <th style="text-align: right;">Harga Jual</th>
                <th style="text-align: right;">Subtotal</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($so->items as $item): ?>
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
                    <td style="text-align: right;"><?= (int) $item->qty ?> <?= View::e($item->unit ?? '') ?></td>
                    <td style="text-align: right;"><?= number_format($item->sellPrice, 2) ?></td>
                    <td style="text-align: right;"><?= number_format($item->qty * $item->sellPrice, 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="5" style="text-align:right;"><strong>Total</strong></td>
                <td style="text-align: right;"><strong><?= number_format($total, 2) ?></strong></td>
            </tr>
            </tfoot>
        </table>
    </div>
</div>
