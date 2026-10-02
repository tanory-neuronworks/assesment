<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

use App\Core\Database;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;

EnvLoader::load(__DIR__ . '/../.env');

$pdo = Database::connect([
    'host' => (string) EnvLoader::get('DB_HOST', 'mysql'),
    'port' => (string) EnvLoader::get('DB_PORT', '3306'),
    'name' => (string) EnvLoader::get('DB_NAME', 'inventory'),
    'user' => (string) EnvLoader::get('DB_USER', 'inventory_user'),
    'pass' => (string) EnvLoader::get('DB_PASS', ''),
]);

$products = new MysqlProductRepository($pdo);
$stocks = new MysqlProductStockRepository($pdo);

$activeProducts = $products->all(onlyActive: true);
$totals = $stocks->totalsForProducts(array_map(static fn ($p) => $p->id, $activeProducts));

$lowStock = array_filter(
    $activeProducts,
    static fn ($p) => ($totals[$p->id] ?? 0) <= $p->reorderPoint
);

echo "=== Laporan Produk di Bawah/Sama Dengan Reorder Point ===\n";
echo 'Dijalankan: ' . date('Y-m-d H:i:s') . "\n\n";

if ($lowStock === []) {
    echo "Tidak ada produk yang perlu di-restock.\n";
    exit(0);
}

printf("%-12s %-30s %10s %10s\n", 'SKU', 'Nama Produk', 'Stok', 'Reorder');
echo str_repeat('-', 64) . "\n";

foreach ($lowStock as $product) {
    printf(
        "%-12s %-30s %10d %10d\n",
        $product->sku,
        mb_strimwidth($product->name, 0, 30, '...'),
        $totals[$product->id] ?? 0,
        $product->reorderPoint
    );
}

echo "\nTotal: " . count($lowStock) . " produk perlu direstock.\n";
