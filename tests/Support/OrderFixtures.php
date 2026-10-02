<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Entity\Customer;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Entity\Supplier;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlProductRepository;
use App\Repository\Mysql\MysqlPurchaseOrderRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlSupplierRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;

/**
 * DB fixtures for the PO/SO controller tests. Everything is written through
 * the transactional PDO of IntegrationTestCase, so it is rolled back in
 * tearDown. Requires $this->pdo and $this->categoryService (ControllerTestCase).
 */
trait OrderFixtures
{
    private function makeSupplier(?string $name = null, bool $active = true): Supplier
    {
        $supplier = new Supplier(null, $name ?? 'Sup ' . uniqid(), '0812', 'Jl. Test', $active);
        (new MysqlSupplierRepository($this->pdo))->create($supplier);

        return $supplier;
    }

    private function makeCustomer(?string $name = null, bool $active = true): Customer
    {
        $customer = new Customer(null, $name ?? 'Cust ' . uniqid(), '0813', 'Jl. Test', $active);
        (new MysqlCustomerRepository($this->pdo))->create($customer);

        return $customer;
    }

    private function makeWarehouse(?string $name = null, bool $active = true): Warehouse
    {
        $warehouse = new Warehouse(null, $name ?? 'Gudang ' . uniqid(), 'Lokasi Test', $active);
        (new MysqlWarehouseRepository($this->pdo))->create($warehouse);

        return $warehouse;
    }

    private function makeProduct(bool $active = true, float $cost = 1000, float $sell = 2500): Product
    {
        $category = $this->categoryService->create(['name' => 'OCat ' . uniqid(), 'description' => 'test']);
        $product = new Product(null, 'OSKU-' . strtoupper(uniqid()), 'Produk ' . uniqid(), (int) $category->id, 'pcs', $cost, $sell, 5, null, $active);
        (new MysqlProductRepository($this->pdo))->create($product);

        return $product;
    }

    private function setStock(int $productId, int $warehouseId, int $qty): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
        );
        $stmt->execute([$productId, $warehouseId, $qty]);
    }

    private function stockOf(int $productId, int $warehouseId): int
    {
        $stmt = $this->pdo->prepare('SELECT quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?');
        $stmt->execute([$productId, $warehouseId]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function ledgerFor(string $referenceType, int $referenceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stock_ledger WHERE reference_type = ? AND reference_id = ? ORDER BY id');
        $stmt->execute([$referenceType, $referenceId]);

        return $stmt->fetchAll();
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function poStatus(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM purchase_orders WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    private function soStatus(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM sales_orders WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param list<array{0:Product,1:int}> $lines [product, qtyOrdered]
     */
    private function seedPo(
        Supplier $supplier,
        Warehouse $warehouse,
        int $createdBy,
        array $lines,
        PurchaseOrderStatus $status = PurchaseOrderStatus::Ordered,
        ?string $orderDate = null,
    ): PurchaseOrder {
        $items = array_map(
            static fn (array $l): PurchaseOrderItem => new PurchaseOrderItem(null, 0, (int) $l[0]->id, $l[1], 0, 1000.0),
            $lines
        );
        $po = new PurchaseOrder(null, (int) $supplier->id, (int) $warehouse->id, $status, $orderDate ?? date('Y-m-d'), $createdBy, $items);
        (new MysqlPurchaseOrderRepository($this->pdo))->create($po);

        return $po;
    }

    /**
     * @param list<array{0:Product,1:int}> $lines [product, qty]
     */
    private function seedSo(
        Customer $customer,
        Warehouse $warehouse,
        int $createdBy,
        array $lines,
        SalesOrderStatus $status = SalesOrderStatus::Draft,
        ?string $orderDate = null,
    ): SalesOrder {
        $items = array_map(
            static fn (array $l): SalesOrderItem => new SalesOrderItem(null, 0, (int) $l[0]->id, $l[1], 2500.0),
            $lines
        );
        $so = new SalesOrder(null, (int) $customer->id, (int) $warehouse->id, $status, $orderDate ?? date('Y-m-d'), $createdBy, null, $items);
        (new MysqlSalesOrderRepository($this->pdo))->create($so);

        return $so;
    }
}
