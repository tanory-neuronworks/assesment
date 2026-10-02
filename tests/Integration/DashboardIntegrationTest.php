<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Service\DashboardService;

final class DashboardIntegrationTest extends IntegrationTestCase
{
    public function test_inventory_value_and_low_stock_count_match_hand_computed_expectation(): void
    {
        $categories = new MysqlCategoryRepository($this->pdo);
        $categoryId = $categories->create(new Category(null, 'ITEST Dash Category ' . uniqid()));

        $products = new MysqlProductRepository($this->pdo);
        $marker = uniqid();
        $lowProductId = $products->create(new Product(null, "ITEST-DASH-LOW-{$marker}", 'Low Stock Product', $categoryId, 'pcs', 100, 150, 20));
        $normalProductId = $products->create(new Product(null, "ITEST-DASH-NORMAL-{$marker}", 'Normal Stock Product', $categoryId, 'pcs', 50, 80, 5));

        $warehouses = new MysqlWarehouseRepository($this->pdo);
        $warehouseId = $warehouses->create(new Warehouse(null, 'ITEST Dash Warehouse ' . uniqid(), 'Nowhere'));

        $before = (new MysqlProductRepository($this->pdo))->inventoryValue();

        $stmt = $this->pdo->prepare('INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, ?, ?)');
        $stmt->execute([$lowProductId, $warehouseId, 5]); // below reorder point 20
        $stmt->execute([$normalProductId, $warehouseId, 100]); // above reorder point 5

        $purchaseOrders = new MysqlPurchaseOrderRepository($this->pdo);
        $salesOrders = new MysqlSalesOrderRepository($this->pdo);
        $service = new DashboardService($products, $purchaseOrders, $salesOrders);

        $stats = $service->forAdmin();

        $expectedAddedValue = 5 * 100 + 100 * 50; // 500 + 5000 = 5500
        $this->assertSame($before + $expectedAddedValue, $stats['inventoryValue']);
        $this->assertGreaterThanOrEqual(1, $stats['lowStockCount']);
    }
}
