<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Repository\Mysql\PdoTransactionManager;
use App\Service\GoodsIssueService;

final class GoodsIssueIntegrationTest extends IntegrationTestCase
{
    private function seedFixtures(): array
    {
        $categories = new MysqlCategoryRepository($this->pdo);
        $categoryId = $categories->create(new Category(null, 'ITEST GI Category ' . uniqid()));

        $products = new MysqlProductRepository($this->pdo);
        $warehouses = new MysqlWarehouseRepository($this->pdo);
        $customers = new MysqlCustomerRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);

        $warehouseId = $warehouses->create(new Warehouse(null, 'ITEST GI Warehouse ' . uniqid(), 'Nowhere'));
        $customerId = $customers->create(new Customer(null, 'ITEST GI Customer ' . uniqid(), '', ''));
        $userId = $users->create(new User(null, 'ITEST GI User', 'itest_gi_' . uniqid(), 'itest_gi_' . uniqid() . '@example.test', password_hash('x', PASSWORD_DEFAULT), Role::WarehouseStaff, true));

        return compact('categoryId', 'products', 'warehouses', 'warehouseId', 'customerId', 'userId');
    }

    private function seedProductWithStock(array $fixtures, int $stock): int
    {
        $productId = $fixtures['products']->create(new Product(
            null, 'ITEST-GI-' . uniqid(), 'ITEST GI Product', $fixtures['categoryId'], 'pcs', 100, 150, 5
        ));

        $stmt = $this->pdo->prepare('INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, ?, ?)');
        $stmt->execute([$productId, $fixtures['warehouseId'], $stock]);

        return $productId;
    }

    private function seedApprovedSo(array $fixtures, array $items): SalesOrder
    {
        $orders = new MysqlSalesOrderRepository($this->pdo);
        $so = new SalesOrder(
            id: null,
            customerId: $fixtures['customerId'],
            warehouseId: $fixtures['warehouseId'],
            status: SalesOrderStatus::Approved,
            orderDate: '2026-09-01',
            createdBy: $fixtures['userId'],
            approvedBy: $fixtures['userId'],
            items: array_map(
                static fn (array $i) => new SalesOrderItem(null, 0, $i['productId'], $i['qty'], 1000),
                $items
            ),
        );
        $orders->create($so);

        return $so;
    }

    private function makeService(): GoodsIssueService
    {
        return new GoodsIssueService(
            new PdoTransactionManager($this->pdo),
            new MysqlSalesOrderRepository($this->pdo),
            new MysqlProductStockRepository($this->pdo),
            new MysqlStockLedgerRepository($this->pdo),
        );
    }

    private function performer(array $fixtures): User
    {
        return new User($fixtures['userId'], 'ITEST GI User', 'itest_gi_user', 'x@test.local', 'hash', Role::WarehouseStaff, true);
    }

    public function test_goods_issue_decreases_stock_and_writes_ledger_together(): void
    {
        $fixtures = $this->seedFixtures();
        $productId = $this->seedProductWithStock($fixtures, 20);
        $so = $this->seedApprovedSo($fixtures, [['productId' => $productId, 'qty' => 8]]);

        $stocksRepo = new MysqlProductStockRepository($this->pdo);
        $ledgerRepo = new MysqlStockLedgerRepository($this->pdo);

        $updated = $this->makeService()->issue($so->id, $this->performer($fixtures));

        $this->assertSame(SalesOrderStatus::Fulfilled, $updated->status);
        $this->assertSame(12, $stocksRepo->totalForProduct($productId));
        $this->assertCount(1, $ledgerRepo->findByProduct($productId));
    }

    public function test_insufficient_stock_on_one_line_rolls_back_the_entire_issue(): void
    {
        $fixtures = $this->seedFixtures();
        $sufficientProductId = $this->seedProductWithStock($fixtures, 20);
        $shortProductId = $this->seedProductWithStock($fixtures, 2);
        $so = $this->seedApprovedSo($fixtures, [
            ['productId' => $sufficientProductId, 'qty' => 8],
            ['productId' => $shortProductId, 'qty' => 10],
        ]);

        $stocksRepo = new MysqlProductStockRepository($this->pdo);
        $orders = new MysqlSalesOrderRepository($this->pdo);

        try {
            $this->makeService()->issue($so->id, $this->performer($fixtures));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertSame(20, $stocksRepo->totalForProduct($sufficientProductId), 'first line must be rolled back too');
        $this->assertSame(2, $stocksRepo->totalForProduct($shortProductId));

        $reloaded = $orders->findById($so->id);
        $this->assertNotNull($reloaded);
        $this->assertSame(SalesOrderStatus::Approved, $reloaded->status, 'SO must not be marked Fulfilled on a failed issue');
    }

    public function test_oversell_scenario_second_issue_is_rejected_when_stock_runs_out(): void
    {
        $fixtures = $this->seedFixtures();
        $productId = $this->seedProductWithStock($fixtures, 10);
        $so1 = $this->seedApprovedSo($fixtures, [['productId' => $productId, 'qty' => 8]]);
        $so2 = $this->seedApprovedSo($fixtures, [['productId' => $productId, 'qty' => 8]]);

        $stocksRepo = new MysqlProductStockRepository($this->pdo);
        $service = $this->makeService();

        $first = $service->issue($so1->id, $this->performer($fixtures));
        $this->assertSame(SalesOrderStatus::Fulfilled, $first->status);
        $this->assertSame(2, $stocksRepo->totalForProduct($productId));

        try {
            $service->issue($so2->id, $this->performer($fixtures));
            $this->fail('Expected ValidationException - stock should be insufficient for the second issue');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertSame(2, $stocksRepo->totalForProduct($productId), 'stock must stay at 2, not go negative');
    }
}
