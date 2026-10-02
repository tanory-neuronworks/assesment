<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ReferenceType;
use App\Entity\Role;
use App\Entity\StockLedgerEntry;
use App\Entity\StockMovementType;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;

final class ReportIntegrationTest extends IntegrationTestCase
{
    public function test_find_in_range_includes_entries_inside_range_and_excludes_outside(): void
    {
        $warehouses = new MysqlWarehouseRepository($this->pdo);
        $warehouseId = $warehouses->create(new Warehouse(null, 'ITEST Report Warehouse ' . uniqid(), 'Nowhere'));

        $users = new MysqlUserRepository($this->pdo);
        $userId = $users->create(new User(null, 'ITEST Report User', 'itest_report_' . uniqid(), 'itest_report_' . uniqid() . '@example.test', password_hash('x', PASSWORD_DEFAULT), Role::Admin, true));

        $categories = new MysqlCategoryRepository($this->pdo);
        $categoryId = $categories->create(new Category(null, 'ITEST Report Category ' . uniqid()));
        $products = new MysqlProductRepository($this->pdo);
        $productId = $products->create(new Product(null, 'ITEST-REPORT-' . uniqid(), 'Report Product', $categoryId, 'pcs', 100, 150, 5));

        $ledger = new MysqlStockLedgerRepository($this->pdo);
        $ledger->create(new StockLedgerEntry(
            id: null,
            productId: $productId,
            warehouseId: $warehouseId,
            movementType: StockMovementType::Adjustment,
            quantity: 10,
            referenceType: ReferenceType::Adjustment,
            referenceId: null,
            performedBy: $userId,
        ));

        $today = date('Y-m-d');
        $inRange = $ledger->findInRange($today, $today);
        $outOfRange = $ledger->findInRange('2000-01-01', '2000-01-02');

        $found = array_filter($inRange, static fn (StockLedgerEntry $e) => $e->productId === $productId);
        $this->assertNotEmpty($found);

        $foundOutside = array_filter($outOfRange, static fn (StockLedgerEntry $e) => $e->productId === $productId);
        $this->assertEmpty($foundOutside);
    }
}
