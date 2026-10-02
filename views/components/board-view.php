<?php
/**
 * Generic Kanban (status columns) + accordion (grouped-by-entity) board,
 * with an optional search box + date-range filter wired to server-side
 * debounced AJAX (see board-search.js): typing/changing a filter re-fetches
 * components.board-results from $searchAction with the current filter
 * values, so every total on the board (stats, per-column, per-customer)
 * reflects only the matching orders - not just a client-side show/hide of
 * the full set.
 *
 * @var array<int,array{label:string,value:string}> $stats
 * @var array<int,array{label:string,badgeClass:string,count:int,total:string,groups:array<int,array<string,mixed>>}> $columns
 * @var string $emptyMessage
 * @var string|null $searchPlaceholder omit to hide the whole filter bar
 *      (search box + date range) entirely
 * @var string $searchValue current search term (server-rendered, so a full
 *      page reload or the back button keeps it)
 * @var string $fromValue current "from" date (Y-m-d)
 * @var string $toValue current "to" date (Y-m-d)
 * @var string $searchAction base URL the debounced search fetches from
 */

use App\Core\Icon;
use App\Core\View;

$searchPlaceholder = $searchPlaceholder ?? null;
$searchValue = $searchValue ?? '';
$fromValue = $fromValue ?? '';
$toValue = $toValue ?? '';
?>
<?php if ($searchPlaceholder !== null): ?>
    <div class="board-filters" data-board-filters data-board-search-action="<?= View::e($searchAction) ?>">
        <label class="board-search">
            <span class="board-search__icon"><?= Icon::svg('search', 14) ?></span>
            <input type="search" data-board-search value="<?= View::e($searchValue) ?>" placeholder="<?= View::e($searchPlaceholder) ?>">
        </label>
        <label class="board-filter-date">
            <span class="board-filter-date__label">Dari</span>
            <input type="date" data-board-from value="<?= View::e($fromValue) ?>" aria-label="Dari tanggal">
        </label>
        <label class="board-filter-date">
            <span class="board-filter-date__label">Sampai</span>
            <input type="date" data-board-to value="<?= View::e($toValue) ?>" aria-label="Sampai tanggal">
        </label>
    </div>
<?php endif; ?>

<div data-board-results>
    <?= View::renderFile('components.board-results', [
        'stats' => $stats,
        'columns' => $columns,
        'emptyMessage' => $emptyMessage,
        'expanded' => $searchValue !== '',
    ]) ?>
</div>
