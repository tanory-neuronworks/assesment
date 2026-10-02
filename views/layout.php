<?php
/** @var string $content */
/** @var string $title */
/** @var \App\Entity\User|null $user */
/** @var array<string,string> $flashes */

use App\Core\Icon;
use App\Core\View;
use App\Entity\Role;

$flashes = $flashes ?? [];
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$isActive = static function (string $path) use ($currentPath): string {
    if ($path === '/') {
        return $currentPath === '/' ? ' active' : '';
    }

    return str_starts_with($currentPath, $path) ? ' active' : '';
};

$navLink = static function (string $href, string $icon, string $label) use ($isActive): string {
    return '<li><a class="' . trim($isActive($href)) . '" href="' . View::e($href) . '">'
        . Icon::svg($icon, 17) . '<span class="label">' . View::e($label) . '</span></a></li>';
};
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($title ?? 'Inventory & Order Management') ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <?php if ($user !== null): ?>
        <aside class="sidebar" data-sidebar>
            <div class="sidebar__brand">
                <button type="button" class="sidebar__brand-mark" data-sidebar-collapse aria-label="Buka/ciutkan sidebar">
                    <?= Icon::svg('package', 17) ?>
                </button>
                <span>Inventory &amp; Order</span>
                <button type="button" class="sidebar__collapse" data-sidebar-collapse aria-label="Ciutkan sidebar">
                    <?= Icon::svg('chevrons-left', 15) ?>
                </button>
            </div>
            <button type="button" class="sidebar__toggle" data-sidebar-toggle>
                <?= Icon::svg('menu', 16) ?>
                <span>Menu</span>
            </button>
            <div class="sidebar__body" data-sidebar-body>
                <span class="sidebar__role"><?= View::e($user->role->label()) ?></span>
                <nav>
                    <div class="sidebar__group">
                        <ul>
                            <?= $navLink('/', 'dashboard', 'Dashboard') ?>
                        </ul>
                    </div>

                    <div class="sidebar__group">
                        <div class="sidebar__group-label">Transaksi</div>
                        <ul>
                            <?= $navLink('/sales-orders', 'cart', 'Sales Order') ?>
                            <?php if ($user->role === Role::Admin || $user->role === Role::WarehouseStaff): ?>
                                <?= $navLink('/purchase-orders', 'truck', 'Purchase Order') ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="sidebar__group">
                        <div class="sidebar__group-label">Master Data</div>
                        <ul>
                            <?= $navLink('/products', 'box', 'Produk') ?>
                            <?= $navLink('/categories', 'tag', 'Kategori') ?>
                            <?= $navLink('/warehouses', 'warehouse', 'Gudang') ?>
                            <?php if ($user->role === Role::Admin): ?>
                                <?= $navLink('/suppliers', 'truck', 'Supplier') ?>
                                <?= $navLink('/customers', 'users', 'Customer') ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <?php if ($user->role === Role::Admin): ?>
                        <div class="sidebar__group">
                            <div class="sidebar__group-label">Administrasi</div>
                            <ul>
                                <?= $navLink('/users', 'shield', 'User') ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </nav>
                <form class="logout-form" method="post" action="/logout">
                    <?= \App\Core\Csrf::field() ?>
                    <button type="submit" class="btn btn--sm">
                        <?= Icon::svg('log-out', 15) ?>
                        <span class="label">Logout (<?= View::e($user->name) ?>)</span>
                    </button>
                </form>
            </div>
        </aside>
    <?php endif; ?>

    <main class="main">
        <?php foreach ($flashes as $type => $message): ?>
            <div class="flash flash--<?= View::e($type) ?>"><?= View::e($message) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </main>
</div>

<div class="modal" data-image-modal hidden>
    <div class="modal__backdrop" data-image-modal-close></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-label="Pratinjau gambar">
        <img src="" alt="" class="modal__image" data-image-modal-img>
        <div class="modal__placeholder" data-image-modal-placeholder hidden>
            <?= Icon::svg('box', 40) ?>
            <p>Belum ada gambar.</p>
        </div>
        <p class="modal__error" data-image-modal-error hidden></p>
        <form class="modal__actions" data-image-modal-form enctype="multipart/form-data">
            <?= \App\Core\Csrf::field() ?>
            <input type="file" id="image-modal-file" aria-label="Pilih gambar" name="image" accept="image/jpeg,image/png,image/webp" data-image-modal-file hidden>
            <button type="button" class="btn btn--primary" data-image-modal-change hidden>
                <?= Icon::svg('edit', 15) ?> Ganti Gambar
            </button>
        </form>
    </div>
</div>

<script src="/assets/js/main.js"></script>
<script src="/assets/js/validation.js"></script>
<script src="/assets/js/async-dropdown.js"></script>
<script src="/assets/js/ajax-table.js"></script>
<script src="/assets/js/enum-dropdown.js"></script>
<script src="/assets/js/image-preview.js"></script>
<script src="/assets/js/view-tabs.js"></script>
<script src="/assets/js/board-search.js"></script>
<script src="/assets/js/gallery-search.js"></script>
<script src="/assets/js/gallery-load-more.js"></script>
</body>
</html>
