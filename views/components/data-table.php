<?php
/**
 * Reusable table shell: an optional toolbar (entries-per-page picker +
 * table-attached search) that stays fixed across AJAX swaps, and the
 * search/page-dependent results (rows + pagination), rendered via
 * components.data-table-results and wrapped in [data-ajax-table] - that's
 * the only part an AJAX response ever replaces.
 *
 * @var array<int,string|array{label:string,align?:'right'|'center'}> $columns
 * @var bool $hasThumbnail prepends a sticky-left "Foto" column
 * @var bool $hasActions
 * @var 'right'|'center' $actionsAlign right for a burger menu (many
 *      actions), center for a single plain button (nothing to align away
 *      from)
 * @var string $rows
 * @var string $emptyMessage
 * @var \App\Core\Pagination|null $pagination
 * @var string|null $queryBase query string with "page"/"per_page" stripped,
 *      required when $pagination is set
 * @var array{name:string,value:?string,action:string,extra?:array<string,?string>}|null $search
 *      search matches every visible column, so the input's placeholder is
 *      fixed here rather than repeated by each caller
 */

use App\Core\Icon;
use App\Core\View;

$hasThumbnail = $hasThumbnail ?? false;
$hasActions = $hasActions ?? false;
$actionsAlign = $actionsAlign ?? 'right';
$pagination = $pagination ?? null;
$search = $search ?? null;
$queryBase = $queryBase ?? '';
?>
<div class="card">
    <?php if ($pagination !== null || $search !== null): ?>
        <div class="table-toolbar">
            <?php if ($pagination !== null): ?>
                <div class="table-toolbar__entries">
                    <span>Show</span>
                    <?= View::renderFile('components.enum-dropdown', [
                        'mode' => 'navigate',
                        'selected' => (string) $pagination->perPage,
                        'placeholder' => (string) $pagination->perPage,
                        'options' => array_map(
                            static fn (int $n) => [
                                'value' => (string) $n,
                                'label' => (string) $n,
                                'href' => '?' . ($queryBase === '' ? '' : $queryBase . '&') . "per_page={$n}",
                            ],
                            [5, 10, 25, 50]
                        ),
                    ]) ?>
                    <span>entries</span>
                </div>
            <?php endif; ?>
            <?php if ($search !== null): ?>
                <form method="get" action="<?= View::e($search['action']) ?>" class="table-toolbar__search">
                    <?php foreach (($search['extra'] ?? []) as $fieldName => $fieldValue): ?>
                        <?php if ($fieldValue !== null && $fieldValue !== ''): ?>
                            <input type="hidden" name="<?= View::e($fieldName) ?>" value="<?= View::e((string) $fieldValue) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($pagination !== null): ?>
                        <input type="hidden" name="per_page" value="<?= (int) $pagination->perPage ?>">
                    <?php endif; ?>
                    <span class="table-toolbar__search-icon"><?= Icon::svg('search', 14) ?></span>
                    <input type="search" aria-label="Cari" name="<?= View::e($search['name']) ?>" value="<?= View::e($search['value'] ?? '') ?>" placeholder="Cari di semua kolom...">
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div data-ajax-table><?= View::renderFile('components.data-table-results', [
        'columns' => $columns,
        'hasThumbnail' => $hasThumbnail,
        'hasActions' => $hasActions,
        'actionsAlign' => $actionsAlign,
        'rows' => $rows,
        'emptyMessage' => $emptyMessage,
        'pagination' => $pagination,
        'queryBase' => $queryBase,
    ]) ?></div>
</div>
