<?php
/** @var string $tableHtml */
/** @var \App\Entity\User|null $user */
/** @var \App\Entity\Category[] $categories */
/** @var array<string,mixed> $filters */
/** @var array<int,array{name:string,products:array<int,array<string,mixed>>}> $galleryGroups */

use App\Core\View;
use App\Entity\Role;

$isAdmin = $user?->role === Role::Admin;
$filters = $filters ?? [];
$defaultView = $defaultView ?? 'gallery';

echo View::renderFile('components.breadcrumb', ['items' => [['label' => 'Produk']]]);
?>
<div class="topbar">
    <h1>Produk</h1>
    <?php if ($isAdmin): ?>
        <a href="/products/create" class="btn btn--primary"><?= \App\Core\Icon::svg('plus', 15) ?> Tambah Produk</a>
    <?php endif; ?>
</div>

<?= View::renderFile('components.view-tabs', [
    'default' => $defaultView,
    'tabs' => [
        ['key' => 'table', 'label' => 'Tabel', 'icon' => 'inbox'],
        ['key' => 'gallery', 'label' => 'Galeri', 'icon' => 'package'],
    ],
]) ?>

<div data-view-panel="table" <?= $defaultView === 'table' ? '' : 'hidden' ?>>
    <div class="card">
        <form method="get" action="/products" class="filters">
            <input type="hidden" name="q" value="<?= View::e($filters['q'] ?? '') ?>">
            <input type="hidden" name="view" value="table">
            <?= View::renderFile('components.enum-dropdown', [
                'name' => 'category',
                'placeholder' => 'Semua Kategori',
                'selected' => $filters['category'] ?? '',
                'options' => array_map(
                    static fn ($c) => ['value' => (string) $c->id, 'label' => $c->name],
                    $categories
                ),
            ]) ?>
            <?= View::renderFile('components.enum-dropdown', [
                'name' => 'stock',
                'placeholder' => 'Semua Status Stok',
                'selected' => $filters['stock'] ?? '',
                'options' => [
                    ['value' => 'low', 'label' => 'Low Stock'],
                    ['value' => 'normal', 'label' => 'Normal'],
                ],
            ]) ?>
            <button type="submit" class="btn btn--sm"><?= \App\Core\Icon::svg('search', 14) ?> Cari</button>
            <a href="/products" class="btn btn--sm">Reset</a>
        </form>
    </div>

    <?= $tableHtml ?>
</div>

<div data-view-panel="gallery" <?= $defaultView === 'gallery' ? '' : 'hidden' ?>>
    <?= View::renderFile('components.gallery-view', [
        'groups' => $galleryGroups,
        'isAdmin' => $isAdmin,
        'searchPlaceholder' => 'Cari SKU atau nama produk...',
        'searchValue' => '',
        'searchAction' => '/products',
    ]) ?>
</div>
