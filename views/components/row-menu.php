<?php
/**
 * Reusable "burger" (kebab) row-action menu.
 *
 * @var array<int,array{
 *     type: 'link'|'form'|'divider',
 *     icon?: string,
 *     label?: string,
 *     href?: string,
 *     action?: string,
 *     confirm?: string,
 *     fields?: array<string,string>,
 *     danger?: bool
 * }> $items
 */

use App\Core\Csrf;
use App\Core\Icon;
use App\Core\View;

$items = $items ?? [];
?>
<div class="row-menu">
    <button type="button" class="row-menu__toggle" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="Aksi">
        <?= Icon::svg('dots-vertical', 16) ?>
    </button>
    <ul class="dropdown-panel row-menu__panel" data-menu-panel role="menu" hidden>
        <?php foreach ($items as $item): ?>
            <?php if (($item['type'] ?? '') === 'divider'): ?>
                <li><hr class="dropdown-divider"></li>
            <?php elseif (($item['type'] ?? '') === 'link'): ?>
                <li>
                    <a class="dropdown-item" role="menuitem" href="<?= View::e($item['href']) ?>">
                        <?= Icon::svg($item['icon'] ?? 'box', 15) ?><span><?= View::e($item['label']) ?></span>
                    </a>
                </li>
            <?php elseif (($item['type'] ?? '') === 'form'): ?>
                <li>
                    <form method="post" action="<?= View::e($item['action']) ?>" <?= isset($item['confirm']) ? 'data-confirm="' . View::e($item['confirm']) . '"' : '' ?>>
                        <?= Csrf::field() ?>
                        <?php foreach (($item['fields'] ?? []) as $fieldName => $fieldValue): ?>
                            <input type="hidden" name="<?= View::e($fieldName) ?>" value="<?= View::e((string) $fieldValue) ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="dropdown-item <?= !empty($item['danger']) ? 'dropdown-item--danger' : '' ?>" role="menuitem">
                            <?= Icon::svg($item['icon'] ?? 'box', 15) ?><span><?= View::e($item['label']) ?></span>
                        </button>
                    </form>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</div>
