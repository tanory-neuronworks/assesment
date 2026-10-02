<?php
/**
 * The part of the gallery that changes with search: category groups, each
 * a grid of product cards (capped per group - the rest loads on demand via
 * "Muat Lebih Banyak", see gallery-load-more.js). Split out from
 * gallery-view.php so an AJAX search only ever replaces this fragment,
 * never the search input itself.
 *
 * @var array<int,array{categoryId:int,name:string,products:array<int,array<string,mixed>>,total:int,hasMore:bool}> $groups
 * @var bool $isAdmin
 */

use App\Core\Icon;
use App\Core\View;

$totalCategories = count($groups);
$totalProducts = array_sum(array_column($groups, 'total'));
?>
<div class="stat-grid gallery-stats">
    <div class="stat-card">
        <div class="stat-card__value"><?= $totalCategories ?></div>
        <div class="stat-card__label">Kategori</div>
    </div>
    <div class="stat-card">
        <div class="stat-card__value"><?= $totalProducts ?></div>
        <div class="stat-card__label">Total Produk</div>
    </div>
</div>
<?php if ($groups === []): ?>
    <div class="empty-state">
        <div class="empty-state__icon"><?= Icon::svg('inbox', 28) ?></div>
        <p>Tidak ada produk yang cocok.</p>
    </div>
<?php else: ?>
    <?php foreach ($groups as $group): ?>
        <?php
        // Empty products + hasMore only ever coincide on the very first,
        // pre-JS-measurement render (limitPerGroup=0 - see ProductController)
        // - a search result always asks for a real, already-measured count,
        // so this loading state only ever shows right when the page opens.
        $isInitialLoad = $group['products'] === [] && $group['hasMore'];
        ?>
        <div class="gallery-group">
            <h3 class="gallery-group__title">
                <?= View::e($group['name']) ?>
                <span class="gallery-group__count"><?= (int) $group['total'] ?></span>
            </h3>
            <div class="gallery-loading" data-gallery-loading <?= $isInitialLoad ? '' : 'hidden' ?>>
                <span class="gallery-loading__spinner"></span> Memuat produk...
            </div>
            <div
                class="gallery-grid"
                data-gallery-grid
                data-category-id="<?= (int) $group['categoryId'] ?>"
                data-total="<?= (int) $group['total'] ?>"
            >
                <?php foreach ($group['products'] as $p): ?>
                    <?= View::renderFile('components.gallery-card', ['p' => $p, 'isAdmin' => $isAdmin]) ?>
                <?php endforeach; ?>
            </div>
            <?php if ($group['hasMore']): ?>
                <button
                    type="button"
                    class="btn gallery-load-more"
                    data-gallery-load-more
                    data-category-id="<?= (int) $group['categoryId'] ?>"
                    data-offset="<?= count($group['products']) ?>"
                    <?= $isInitialLoad ? 'hidden' : '' ?>
                >
                    Muat Lebih Banyak
                </button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
