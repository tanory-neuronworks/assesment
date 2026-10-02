<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ValidationException;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlProductStockRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlStockLedgerRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;
use App\Repository\Mysql\PdoTransactionManager;
use App\Service\GoodsReceiptService;

final class GoodsReceiptIntegrationTest extends IntegrationTestCase
{
    private function seedFixtures(): array
    {
        $categories = new MysqlCategoryRepository($this->pdo);
        $categoryId = $categories->create(new Category(null, 'ITEST Category ' . uniqid()));

        $products = new MysqlProductRepository($this->pdo);
        $productId = $products->create(new Product(null, 'ITEST-GR-' . uniqid(), 'ITEST Product', $categoryId, 'pcs', 100, 150, 5));

        $warehouses = new MysqlWarehouseRepository($this->pdo);
        $warehouseId = $warehouses->create(new Warehouse(null, 'ITEST Warehouse ' . uniqid(), 'Nowhere'));

        $suppliers = new MysqlSupplierRepository($this->pdo);
        $supplierId = $suppliers->create(new Supplier(null, 'ITEST Supplier ' . uniqid(), '', ''));

        $users = new MysqlUserRepository($this->pdo);
        $userId = $users->create(new User(null, 'ITEST User', 'itest_gr_' . uniqid(), 'itest_gr_' . uniqid() . '@example.test', password_hash('x', PASSWORD_DEFAULT), Role::WarehouseStaff, true));

        return compact('productId', 'warehouseId', 'supplierId', 'userId');
    }

    private function seedPo(array $fixtures, int $qtyOrdered): PurchaseOrder
    {
        $orders = new MysqlPurchaseOrderRepository($this->pdo);
        $po = new PurchaseOrder(
            id: null,
            supplierId: $fixtures['supplierId'],
            warehouseId: $fixtures['warehouseId'],
            status: PurchaseOrderStatus::Ordered,
            orderDate: '2026-09-01',
            createdBy: $fixtures['userId'],
            items: [new PurchaseOrderItem(null, 0, $fixtures['productId'], $qtyOrdered, 0, 100)],
        );
        $orders->create($po);

        return $po;
    }

    private function makeService(): GoodsReceiptService
    {
        return new GoodsReceiptService(
            new PdoTransactionManager($this->pdo),
            new MysqlPurchaseOrderRepository($this->pdo),
            new MysqlProductStockRepository($this->pdo),
            new MysqlStockLedgerRepository($this->pdo),
        );
    }

    private function performer(array $fixtures): User
    {
        return new User($fixtures['userId'], 'ITEST User', 'itest_gr_user', 'x@test.local', 'hash', Role::WarehouseStaff, true);
    }

    public function test_goods_receipt_increases_stock_and_writes_ledger_in_one_call(): void
    {
        $fixtures = $this->seedFixtures();
        $po = $this->seedPo($fixtures, 20);
        $itemId = $po->items[0]->id;

        $stocksRepo = new MysqlProductStockRepository($this->pdo);
        $ledgerRepo = new MysqlStockLedgerRepository($this->pdo);

        $this->makeService()->receive($po->id, [$itemId => 12], $this->performer($fixtures));

        $this->assertSame(12, $stocksRepo->totalForProduct($fixtures['productId']));
        $this->assertCount(1, $ledgerRepo->findByProduct($fixtures['productId']));
    }

    public function test_partial_then_full_receipt_transitions_status_correctly(): void
    {
        $fixtures = $this->seedFixtures();
        $po = $this->seedPo($fixtures, 20);
        $itemId = $po->items[0]->id;
        $service = $this->makeService();

        $afterPartial = $service->receive($po->id, [$itemId => 8], $this->performer($fixtures));
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $afterPartial->status);

        $afterFull = $service->receive($po->id, [$itemId => 12], $this->performer($fixtures));
        $this->assertSame(PurchaseOrderStatus::Received, $afterFull->status);
    }

    public function test_receiving_more_than_remaining_is_rejected_and_leaves_stock_untouched(): void
    {
        $fixtures = $this->seedFixtures();
        $po = $this->seedPo($fixtures, 10);
        $itemId = $po->items[0]->id;
        $stocksRepo = new MysqlProductStockRepository($this->pdo);

        try {
            $this->makeService()->receive($po->id, [$itemId => 999], $this->performer($fixtures));
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame(0, $stocksRepo->totalForProduct($fixtures['productId']));
    }

    public function test_cancelled_po_cannot_receive_goods(): void
    {
        $fixtures = $this->seedFixtures();
        $po = $this->seedPo($fixtures, 10);
        $orders = new MysqlPurchaseOrderRepository($this->pdo);
        $orders->updateStatus($po->id, PurchaseOrderStatus::Cancelled);
        $itemId = $po->items[0]->id;

        $this->expectException(ValidationException::class);
        $this->makeService()->receive($po->id, [$itemId => 5], $this->performer($fixtures));
    }
}
