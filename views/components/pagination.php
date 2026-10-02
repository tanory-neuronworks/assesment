<?php
/** @var \App\Core\Pagination $pagination */
/** @var string $queryBase query string with "page" and "per_page" already stripped */

use App\Core\View;

$queryBase = $queryBase ?? '';
$totalPages = $pagination->totalPages();

$link = static function (int $page) use ($queryBase, $pagination): string {
    $params = $queryBase === '' ? [] : [$queryBase];
    $params[] = "per_page={$pagination->perPage}";
    $params[] = "page={$page}";

    return '?' . implode('&', $params);
};

// Simple sliding window of page numbers around the current page (no
// ellipsis handling - fine for the data volumes this app deals with).
$windowSize = min(5, $totalPages);
$windowStart = max(1, $pagination->page - intdiv($windowSize, 2));
$windowEnd = min($totalPages, $windowStart + $windowSize - 1);
$windowStart = max(1, $windowEnd - $windowSize + 1);

$rangeStart = $pagination->total === 0 ? 0 : (($pagination->page - 1) * $pagination->perPage) + 1;
$rangeEnd = min($pagination->page * $pagination->perPage, $pagination->total);
?>
<?php if ($pagination->total > 0): ?>
    <nav class="pagination" aria-label="Navigasi halaman">
        <p class="pagination__summary">
            Menampilkan <?= $rangeStart ?>-<?= $rangeEnd ?> dari <?= $pagination->total ?> data
        </p>
        <ul class="pagination__pages">
            <li>
                <a href="<?= View::e($link(max(1, $pagination->page - 1))) ?>"
                   class="pagination__link <?= $pagination->hasPrevious() ? '' : 'is-disabled' ?>"
                   rel="prev">&laquo; Prev</a>
            </li>
            <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
                <li>
                    <a href="<?= View::e($link($p)) ?>" class="pagination__link <?= $p === $pagination->page ? 'is-current' : '' ?>" <?= $p === $pagination->page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <li>
                <a href="<?= View::e($link(min($totalPages, $pagination->page + 1))) ?>"
                   class="pagination__link <?= $pagination->hasNext() ? '' : 'is-disabled' ?>"
                   rel="next">Next &raquo;</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>
