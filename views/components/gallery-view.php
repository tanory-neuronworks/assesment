<?php
/**
 * Product "storefront" gallery grouped by category - Tokopedia/Shopee-style
 * cards for browsing the catalog, as an alternative to the flat table.
 * Search is wired to server-side debounced AJAX (see gallery-search.js):
 * typing re-fetches components.gallery-results from $searchAction, so the
 * search input (outside the swapped fragment) never loses focus
 * mid-keystroke - same lesson as the table's and board's own search boxes.
 *
 * @var array<int,array{name:string,products:array<int,array<string,mixed>>}> $groups
 * @var bool $isAdmin
 * @var string|null $searchPlaceholder omit to hide the search box entirely
 * @var string $searchValue current search term (server-rendered, so a full
 *      page reload or the back button keeps it)
 * @var string $searchAction base URL the debounced search fetches from
 */

use App\Core\Icon;
use App\Core\View;

$searchPlaceholder = $searchPlaceholder ?? null;
$searchValue = $searchValue ?? '';
?>
<?php if ($searchPlaceholder !== null): ?>
    <label class="board-search gallery-search-bar">
        <span class="board-search__icon"><?= Icon::svg('search', 14) ?></span>
        <input
            type="search"
            data-gallery-search
            data-gallery-search-action="<?= View::e($searchAction) ?>"
            value="<?= View::e($searchValue) ?>"
            placeholder="<?= View::e($searchPlaceholder) ?>"
        >
    </label>
<?php endif; ?>

<div data-gallery-results>
    <?= View::renderFile('components.gallery-results', [
        'groups' => $groups,
        'isAdmin' => $isAdmin,
    ]) ?>
</div>
