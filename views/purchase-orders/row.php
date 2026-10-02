<?php
/** @var \App\Entity\PurchaseOrder $po */
/** @var string $badgeClass */

use App\Core\Icon;
use App\Core\View;
?>
<tr>
    <td>PO-<?= str_pad((string) $po->id, 5, '0', STR_PAD_LEFT) ?></td>
    <td><?= View::e($po->supplierName ?? '-') ?></td>
    <td><?= View::e($po->warehouseName ?? '-') ?></td>
    <td><?= View::e(View::dateShort($po->orderDate)) ?></td>
    <td><?= View::e($po->createdByName ?? '-') ?></td>
    <td><span class="badge <?= $badgeClass ?>"><?= View::e($po->status->label()) ?></span></td>
    <td class="col-actions">
        <a href="/purchase-orders/<?= (int) $po->id ?>" class="btn btn--sm"><?= Icon::svg('eye', 14) ?> Detail</a>
    </td>
</tr>
