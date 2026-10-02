<?php
/**
 * The part of the board that changes with search: the stat tiles and the
 * Kanban columns/accordions. Split out from board-view.php so an AJAX
 * search only ever replaces this fragment - never the search input itself,
 * which would otherwise lose focus mid-keystroke on every debounced
 * request (the same lesson learned from the table's own search box).
 *
 * @var array<int,array{label:string,value:string}> $stats
 * @var array<int,array{
 *     label:string,
 *     badgeClass:string,
 *     count:int,
 *     total:string,
 *     groups:array<int,array{
 *         name:string,
 *         meta:string,
 *         cards:array<int,array{href:string,title:string,subtitle:string,value:string}>
 *     }>
 * }> $columns
 * @var string $emptyMessage shown when every column is empty
 * @var bool $expanded whether customer accordions start open - true while a
 *      search is active (the user already knows what they're looking for),
 *      false on the default, unfiltered board
 */

use App\Core\Icon;
use App\Core\View;

$expanded = $expanded ?? false;
// A column with nothing in it (no orders in that status at all, or none
// matching the current search/date filter) is just noise on the board -
// skip it entirely rather than rendering an empty card with a "nothing
// here" message. If that leaves no columns at all, show one message for
// the whole board instead of one per column.
$visibleColumns = array_values(array_filter($columns, static fn (array $column): bool => $column['groups'] !== []));
?>
<div class="stat-grid" style="margin-bottom:1.25rem;">
    <?php foreach ($stats as $stat): ?>
        <div class="stat-card">
            <div class="stat-card__value"><?= View::e($stat['value']) ?></div>
            <div class="stat-card__label"><?= View::e($stat['label']) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($visibleColumns === []): ?>
    <div class="empty-state">
        <div class="empty-state__icon"><?= Icon::svg('inbox', 28) ?></div>
        <p><?= View::e($emptyMessage) ?></p>
    </div>
<?php else: ?>
    <div class="board">
        <?php foreach ($visibleColumns as $column): ?>
            <div class="board__column">
                <div class="board__column-header">
                    <span class="badge <?= View::e($column['badgeClass']) ?>"><?= View::e($column['label']) ?></span>
                    <span class="board__column-count"><?= (int) $column['count'] ?></span>
                </div>
                <div class="board__column-total"><?= View::e($column['total']) ?></div>

                <?php foreach ($column['groups'] as $group): ?>
                    <details class="board__group" <?= $expanded ? 'open' : '' ?>>
                        <summary>
                            <span class="board__group-info">
                                <span class="board__group-name"><?= View::e($group['name']) ?></span>
                                <span class="board__group-meta"><?= View::e($group['meta']) ?></span>
                            </span>
                            <span class="board__chevron"><?= Icon::svg('chevron-down', 14) ?></span>
                        </summary>
                        <ul class="board__cards">
                            <?php foreach ($group['cards'] as $card): ?>
                                <li class="board__card">
                                    <a href="<?= View::e($card['href']) ?>">
                                        <strong><?= View::e($card['title']) ?></strong>
                                        <span><?= View::e($card['subtitle']) ?></span>
                                        <span><?= View::e($card['value']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
