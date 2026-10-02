<?php
/** @var \App\Entity\SalesOrder $so */
/** @var string $badgeClass */

use App\Core\Icon;
use App\Core\View;
?>
<tr>
    <td>SO-<?= str_pad((string) $so->id, 5, '0', STR_PAD_LEFT) ?></td>
    <td><?= View::e($so->customerName ?? '-') ?></td>
    <td><?= View::e($so->warehouseName ?? '-') ?></td>
    <td><?= View::e(View::dateShort($so->orderDate)) ?></td>
    <td><?= View::e($so->createdByName ?? '-') ?></td>
    <td><span class="badge <?= $badgeClass ?>"><?= View::e($so->status->label()) ?></span></td>
    <td class="col-actions">
        <a href="/sales-orders/<?= (int) $so->id ?>" class="btn btn--sm"><?= Icon::svg('eye', 14) ?> Detail</a>
    </td>
</tr>
