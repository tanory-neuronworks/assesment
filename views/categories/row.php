<?php
/** @var \App\Entity\Category $cat */
/** @var bool $isAdmin */

use App\Core\View;
?>
<tr>
    <td><?= View::e($cat->name) ?></td>
    <td><?= View::e($cat->description) ?></td>
    <?php if ($isAdmin): ?>
        <td class="col-actions">
            <a href="/categories/<?= (int) $cat->id ?>/edit" class="btn btn--sm"><?= \App\Core\Icon::svg('edit', 14) ?> Edit</a>
        </td>
    <?php endif; ?>
</tr>
