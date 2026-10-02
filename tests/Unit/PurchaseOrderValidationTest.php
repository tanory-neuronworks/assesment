<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Role;
use App\Entity\Supplier;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryProductStockRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemorySupplierRepository;
use App\Repository\InMemory\InMemoryWarehouseRepository;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderValidationTest extends TestCase
{
    private function makeService(): array
    {
        $suppliers = new InMemorySupplierRepository();
        $supplierId = $suppliers->create(new Supplier(null, 'Supplier Aktif', '', '', true));
        $inactiveSupplierId = $suppliers->create(new Supplier(null, 'Supplier Nonaktif', '', '', false));

        $warehouses = new InMemoryWarehouseRepository();
        $warehouseId = $warehouses->create(new Warehouse(null, 'Gudang Utama', 'Jakarta', true));

        $categories = new InMemoryCategoryRepository();
        $categoryId = $categories->create(new Category(null, 'Kategori'));

        $products = new InMemoryProductRepository();
        $productService = new ProductService($products, $categories, new InMemoryProductStockRepository());
        $activeProduct = $productService->create([
            'sku' => 'PO-TEST-1', 'name' => 'Produk A', 'category_id' => $categoryId,
            'unit' => 'pcs', 'cost_price' => 100, 'sell_price' => 150, 'reorder_point' => 5,
        ]);
        $inactiveProduct = $productService->create([
            'sku' => 'PO-TEST-2', 'name' => 'Produk B', 'category_id' => $categoryId,
            'unit' => 'pcs', 'cost_price' => 100, 'sell_price' => 150, 'reorder_point' => 5,
        ]);
        $products->setActive($inactiveProduct->id, false);

        $service = new PurchaseOrderService(new InMemoryPurchaseOrderRepository(), $suppliers, $warehouses, $products);

        return [$service, $supplierId, $inactiveSupplierId, $warehouseId, $activeProduct->id, $inactiveProduct->id];
    }

    private function creator(): User
    {
        return new User(1, 'Admin', 'admin', 'admin@test.local', 'hash', Role::Admin, true);
    }

    private function validPayload(int $supplierId, int $warehouseId): array
    {
        return ['supplier_id' => $supplierId, 'warehouse_id' => $warehouseId, 'order_date' => '2026-09-01'];
    }

    public function test_it_rejects_missing_supplier(): void
    {
        [$service, , , $warehouseId, $productId] = $this->makeService();

        try {
            $service->create(['supplier_id' => '', 'warehouse_id' => $warehouseId, 'order_date' => '2026-09-01'],
                [['product_id' => $productId, 'qty_ordered' => 5, 'cost_price' => 100]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supplier_id', $e->errors());
        }
    }

    public function test_it_rejects_inactive_supplier(): void
    {
        [$service, , $inactiveSupplierId, $warehouseId, $productId] = $this->makeService();

        try {
            $service->create($this->validPayload($inactiveSupplierId, $warehouseId),
                [['product_id' => $productId, 'qty_ordered' => 5, 'cost_price' => 100]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('supplier_id', $e->errors());
        }
    }

    public function test_it_rejects_empty_item_list(): void
    {
        [$service, $supplierId, , $warehouseId] = $this->makeService();

        try {
            $service->create($this->validPayload($supplierId, $warehouseId), [], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
    }

    public function test_it_rejects_inactive_product_in_item_line(): void
    {
        [$service, $supplierId, , $warehouseId, , $inactiveProductId] = $this->makeService();

        try {
            $service->create($this->validPayload($supplierId, $warehouseId),
                [['product_id' => $inactiveProductId, 'qty_ordered' => 5, 'cost_price' => 100]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.product_id', $e->errors());
        }
    }

    public function test_it_rejects_zero_quantity(): void
    {
        [$service, $supplierId, , $warehouseId, $productId] = $this->makeService();

        try {
            $service->create($this->validPayload($supplierId, $warehouseId),
                [['product_id' => $productId, 'qty_ordered' => 0, 'cost_price' => 100]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.qty_ordered', $e->errors());
        }
    }

    public function test_it_creates_po_with_valid_data(): void
    {
        [$service, $supplierId, , $warehouseId, $productId] = $this->makeService();

        $po = $service->create($this->validPayload($supplierId, $warehouseId),
            [['product_id' => $productId, 'qty_ordered' => 10, 'cost_price' => 100]], $this->creator());

        $this->assertSame(\App\Entity\PurchaseOrderStatus::Ordered, $po->status);
        $this->assertCount(1, $po->items);
    }
}
