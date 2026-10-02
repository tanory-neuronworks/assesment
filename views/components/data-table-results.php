<?php
/**
 * The part of a data-table that changes with search/page/per_page: the rows
 * (or empty state) and the pagination bar. Deliberately excludes the toolbar
 * (entries picker + search input) - those live in components.data-table and
 * are never touched by an AJAX swap, so the search input is never
 * destroyed/recreated mid-keystroke (which used to steal focus out from
 * under the user on every debounced search).
 *
 * @var array<int,string|array{label:string,align?:'right'|'center'}> $columns
 *      a plain string column is left-aligned (the default); pass
 *      {label,align} for a numeric column that should read right-aligned
 * @var bool $hasThumbnail prepends a sticky-left "Foto" column (row markup
 *      must include its own <td class="col-thumbnail"> as the first cell,
 *      same convention as $hasActions/"Aksi")
 * @var bool $hasActions
 * @var 'right'|'center' $actionsAlign right for a burger menu (many
 *      actions), center for a single plain button (nothing to align away
 *      from)
 * @var string $rows
 * @var string $emptyMessage
 * @var \App\Core\Pagination|null $pagination
 * @var string|null $queryBase query string with "page"/"per_page" stripped,
 *      required when $pagination is set
 */

use App\Core\Icon;
use App\Core\View;

$hasThumbnail = $hasThumbnail ?? false;
$hasActions = $hasActions ?? false;
$actionsAlign = $actionsAlign ?? 'right';
$pagination = $pagination ?? null;
$queryBase = $queryBase ?? '';
?>
<?php if (trim($rows) === ''): ?>
    <div class="empty-state">
        <div class="empty-state__icon"><?= Icon::svg('inbox', 28) ?></div>
        <p><?= View::e($emptyMessage) ?></p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <?php if ($hasThumbnail): ?>
                    <th class="col-thumbnail">Foto</th>
                <?php endif; ?>
                <?php foreach ($columns as $column): ?>
                    <?php
                    $label = is_array($column) ? $column['label'] : $column;
                    $align = is_array($column) ? ($column['align'] ?? null) : null;
                    ?>
                    <th<?= $align !== null ? ' style="text-align: ' . View::e($align) . ';"' : '' ?>><?= View::e($label) ?></th>
                <?php endforeach; ?>
                <?php if ($hasActions): ?>
                    <th class="col-actions" style="text-align: <?= $actionsAlign === 'center' ? 'center' : 'right' ?>;">Aksi</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody><?= $rows ?></tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($pagination !== null): ?>
    <?= View::renderFile('components.pagination', ['pagination' => $pagination, 'queryBase' => $queryBase]) ?>
<?php endif; ?>
