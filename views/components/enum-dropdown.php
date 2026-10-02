<?php
/**
 * Custom-styled dropdown for a small, fixed set of options (status, role,
 * category, warehouse, page size, ...) - replaces a native <select> so its
 * open state renders consistently with the rest of the UI instead of the
 * browser's own (barely stylable) option list.
 *
 * Two modes:
 * - 'value' (default): picking an option fills a hidden <input
 *   name="$name"> for a surrounding <form> to submit.
 * - 'navigate': picking an option navigates to its `href` directly (e.g.
 *   the pagination page-size picker, which isn't inside the filter form).
 *
 * Every option is matched/highlighted by its `value`, regardless of mode.
 *
 * @var 'value'|'navigate' $mode
 * @var string $name form field name, required when $mode is 'value'
 * @var array<int,array{value:string,label:string,href?:string}> $options
 * @var string|null $selected current value to highlight/display
 * @var string $placeholder shown when nothing matches $selected
 * @var bool $required
 */

use App\Core\Icon;
use App\Core\View;

$mode = $mode ?? 'value';
$name = $name ?? '';
$selected = $selected ?? '';
$required = $required ?? false;

$selectedLabel = null;
foreach ($options as $option) {
    if ((string) $option['value'] === (string) $selected) {
        $selectedLabel = $option['label'];
        break;
    }
}
?>
<div class="enum-dropdown" data-enum-dropdown data-enum-mode="<?= View::e($mode) ?>">
    <?php if ($mode === 'value'): ?>
        <input type="hidden" name="<?= View::e($name) ?>" data-enum-value value="<?= View::e((string) $selected) ?>">
    <?php endif; ?>
    <button type="button" class="btn enum-dropdown__trigger" data-enum-trigger aria-haspopup="listbox" aria-expanded="false" <?= $required ? 'data-required="1"' : '' ?>>
        <span data-enum-label>
            <?php if ($selectedLabel !== null): ?>
                <?= View::e($selectedLabel) ?>
            <?php else: ?>
                <span class="enum-dropdown__placeholder"><?= View::e($placeholder) ?></span>
            <?php endif; ?>
        </span>
        <?= Icon::svg('chevron-down', 15) ?>
    </button>
    <ul class="dropdown-panel enum-dropdown__panel" data-enum-panel role="listbox" hidden>
        <?php foreach ($options as $option): ?>
            <li>
                <button
                    type="button"
                    class="dropdown-item <?= (string) $option['value'] === (string) $selected ? 'is-selected' : '' ?>"
                    data-enum-item
                    data-value="<?= View::e($option['value']) ?>"
                    data-href="<?= View::e($option['href'] ?? '') ?>"
                    role="option"
                    aria-selected="<?= (string) $option['value'] === (string) $selected ? 'true' : 'false' ?>"
                >
                    <?= View::e($option['label']) ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
