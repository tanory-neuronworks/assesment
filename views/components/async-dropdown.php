<?php
/**
 * Searchable, debounced dropdown (Fetch API to /api/lookup/{type}) for
 * fields that reference a potentially large, growing entity list (product,
 * supplier, customer) - a plain <select> preloading every row doesn't scale
 * for these the way it's fine for a handful of warehouses/statuses.
 *
 * @var string $type one of: products, suppliers, customers, warehouses, categories
 * @var string $name form field name for the hidden value input
 * @var string $placeholder
 * @var string|int|null $initialId
 * @var string|null $initialLabel
 * @var bool $required
 * @var string|null $inputId id of the visible search input (default derived from $name)
 */

use App\Core\Icon;
use App\Core\View;

$initialId = $initialId ?? '';
$initialLabel = $initialLabel ?? '';
$required = $required ?? true;
$inputId = $inputId ?? 'async-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
?>
<div class="async-dropdown" data-async-dropdown="<?= View::e($type) ?>">
    <span class="async-dropdown__icon"><?= Icon::svg('search', 14) ?></span>
    <input type="hidden" name="<?= View::e($name) ?>" data-async-value value="<?= View::e((string) $initialId) ?>">
    <input
        type="search"
        id="<?= View::e($inputId) ?>"
        aria-label="<?= View::e($placeholder) ?>"
        class="async-dropdown__input"
        data-async-input
        placeholder="<?= View::e($placeholder) ?>"
        autocomplete="off"
        value="<?= View::e($initialLabel) ?>"
        <?= $required ? 'required' : '' ?>
    >
    <ul class="dropdown-panel async-dropdown__panel" data-async-panel role="listbox" hidden></ul>
</div>
