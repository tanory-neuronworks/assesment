<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Entity\Category;
use App\Entity\Customer;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryCustomerRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryProductStockRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryWarehouseRepository;
use App\Service\ProductService;
use App\Service\SalesOrderService;
use PHPUnit\Framework\TestCase;

final class SalesOrderValidationTest extends TestCase
{
    private function makeService(): array
    {
        $customers = new InMemoryCustomerRepository();
        $customerId = $customers->create(new Customer(null, 'Customer Aktif', '', '', true));
        $inactiveCustomerId = $customers->create(new Customer(null, 'Customer Nonaktif', '', '', false));

        $warehouses = new InMemoryWarehouseRepository();
        $warehouseId = $warehouses->create(new Warehouse(null, 'Gudang Utama', 'Jakarta', true));

        $categories = new InMemoryCategoryRepository();
        $categoryId = $categories->create(new Category(null, 'Kategori'));

        $products = new InMemoryProductRepository();
        $productService = new ProductService($products, $categories, new InMemoryProductStockRepository());
        $activeProduct = $productService->create([
            'sku' => 'SO-TEST-1', 'name' => 'Produk A', 'category_id' => $categoryId,
            'unit' => 'pcs', 'cost_price' => 100, 'sell_price' => 150, 'reorder_point' => 5,
        ]);
        $inactiveProduct = $productService->create([
            'sku' => 'SO-TEST-2', 'name' => 'Produk B', 'category_id' => $categoryId,
            'unit' => 'pcs', 'cost_price' => 100, 'sell_price' => 150, 'reorder_point' => 5,
        ]);
        $products->setActive($inactiveProduct->id, false);

        $service = new SalesOrderService(new InMemorySalesOrderRepository(), $customers, $warehouses, $products);

        return [$service, $customerId, $inactiveCustomerId, $warehouseId, $activeProduct->id, $inactiveProduct->id];
    }

    private function creator(): User
    {
        return new User(2, 'Sales', 'sales', 'sales@test.local', 'hash', Role::Sales, true);
    }

    private function validPayload(int $customerId, int $warehouseId): array
    {
        return ['customer_id' => $customerId, 'warehouse_id' => $warehouseId, 'order_date' => '2026-09-01'];
    }

    public function test_it_rejects_missing_customer(): void
    {
        [$service, , , $warehouseId, $productId] = $this->makeService();

        try {
            $service->create(['customer_id' => '', 'warehouse_id' => $warehouseId, 'order_date' => '2026-09-01'],
                [['product_id' => $productId, 'qty' => 5, 'sell_price' => 150]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }
    }

    public function test_it_rejects_inactive_customer(): void
    {
        [$service, , $inactiveCustomerId, $warehouseId, $productId] = $this->makeService();

        try {
            $service->create($this->validPayload($inactiveCustomerId, $warehouseId),
                [['product_id' => $productId, 'qty' => 5, 'sell_price' => 150]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }
    }

    public function test_it_rejects_empty_item_list(): void
    {
        [$service, $customerId, , $warehouseId] = $this->makeService();

        try {
            $service->create($this->validPayload($customerId, $warehouseId), [], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
    }

    public function test_it_rejects_inactive_product_in_item_line(): void
    {
        [$service, $customerId, , $warehouseId, , $inactiveProductId] = $this->makeService();

        try {
            $service->create($this->validPayload($customerId, $warehouseId),
                [['product_id' => $inactiveProductId, 'qty' => 5, 'sell_price' => 150]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.product_id', $e->errors());
        }
    }

    public function test_it_rejects_zero_quantity(): void
    {
        [$service, $customerId, , $warehouseId, $productId] = $this->makeService();

        try {
            $service->create($this->validPayload($customerId, $warehouseId),
                [['product_id' => $productId, 'qty' => 0, 'sell_price' => 150]], $this->creator());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.qty', $e->errors());
        }
    }

    public function test_it_creates_so_as_draft_with_valid_data(): void
    {
        [$service, $customerId, , $warehouseId, $productId] = $this->makeService();

        $so = $service->create($this->validPayload($customerId, $warehouseId),
            [['product_id' => $productId, 'qty' => 10, 'sell_price' => 150]], $this->creator());

        $this->assertSame(\App\Entity\SalesOrderStatus::Draft, $so->status);
        $this->assertCount(1, $so->items);
    }
}
