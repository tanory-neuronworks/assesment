<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Entity\Category;
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

/**
 * Complements PurchaseOrderValidationTest with the remaining validate() branches.
 */
final class PurchaseOrderValidationBranchesTest extends TestCase
{
    private InMemoryPurchaseOrderRepository $orders;
    private PurchaseOrderService $service;
    private int $supplierId;
    private int $warehouseId;
    private int $inactiveWarehouseId;
    private int $productId;

    protected function setUp(): void
    {
        $suppliers = new InMemorySupplierRepository();
        $this->supplierId = $suppliers->create(new Supplier(null, 'Supplier Aktif', '', '', true));

        $warehouses = new InMemoryWarehouseRepository();
        $this->warehouseId = $warehouses->create(new Warehouse(null, 'Gudang Utama', 'Jakarta', true));
        $this->inactiveWarehouseId = $warehouses->create(new Warehouse(null, 'Gudang Tutup', 'Bandung', false));

        $categories = new InMemoryCategoryRepository();
        $categoryId = $categories->create(new Category(null, 'Kategori'));
        $products = new InMemoryProductRepository();
        $this->productId = (new ProductService($products, $categories, new InMemoryProductStockRepository()))->create([
            'sku' => 'PO-B-1', 'name' => 'Produk A', 'category_id' => $categoryId,
            'unit' => 'pcs', 'cost_price' => 100, 'sell_price' => 150, 'reorder_point' => 5,
        ])->id;

        $this->orders = new InMemoryPurchaseOrderRepository();
        $this->service = new PurchaseOrderService($this->orders, $suppliers, $warehouses, $products);
    }

    private function creator(): User
    {
        return new User(1, 'Admin', 'admin', 'admin@test.local', 'hash', Role::Admin, true);
    }

    private function header(array $override = []): array
    {
        return array_merge(['supplier_id' => $this->supplierId, 'warehouse_id' => $this->warehouseId, 'order_date' => '2026-09-01'], $override);
    }

    private function line(array $override = []): array
    {
        return array_merge(['product_id' => $this->productId, 'qty_ordered' => 5, 'cost_price' => 100], $override);
    }

    /** @return array<string,string> */
    private function errors(array $header, array $items): array
    {
        try {
            $this->service->create($header, $items, $this->creator());
        } catch (ValidationException $e) {
            $this->assertSame([], $this->orders->all(), 'invalid input must not persist an order');

            return $e->errors();
        }
        $this->fail('Expected ValidationException');
    }

    public function test_missing_supplier_key_reports_required_message(): void
    {
        $errors = $this->errors(['warehouse_id' => $this->warehouseId, 'order_date' => '2026-09-01'], [$this->line()]);

        $this->assertSame('Supplier wajib dipilih.', $errors['supplier_id']);
    }

    public function test_non_numeric_supplier_reports_required_message(): void
    {
        $errors = $this->errors($this->header(['supplier_id' => 'abc']), [$this->line()]);

        $this->assertSame('Supplier wajib dipilih.', $errors['supplier_id']);
    }

    public function test_nonexistent_supplier_reports_invalid_message(): void
    {
        $errors = $this->errors($this->header(['supplier_id' => 9999]), [$this->line()]);

        $this->assertSame('Supplier tidak valid atau nonaktif.', $errors['supplier_id']);
    }

    public function test_missing_warehouse_reports_required_message(): void
    {
        $errors = $this->errors($this->header(['warehouse_id' => '']), [$this->line()]);

        $this->assertSame('Gudang tujuan wajib dipilih.', $errors['warehouse_id']);
    }

    public function test_nonexistent_warehouse_reports_invalid_message(): void
    {
        $errors = $this->errors($this->header(['warehouse_id' => 9999]), [$this->line()]);

        $this->assertSame('Gudang tidak valid atau nonaktif.', $errors['warehouse_id']);
    }

    public function test_inactive_warehouse_reports_invalid_message(): void
    {
        $errors = $this->errors($this->header(['warehouse_id' => $this->inactiveWarehouseId]), [$this->line()]);

        $this->assertSame('Gudang tidak valid atau nonaktif.', $errors['warehouse_id']);
    }

    public function test_missing_or_malformed_order_date_is_rejected(): void
    {
        foreach ([null, '', 'bukan-tanggal', '01/09/2026'] as $date) {
            $errors = $this->errors($this->header(['order_date' => $date]), [$this->line()]);

            $this->assertSame('Tanggal order wajib diisi dengan format yang valid.', $errors['order_date']);
        }
    }

    public function test_empty_items_reports_minimum_one_item_message(): void
    {
        $errors = $this->errors($this->header(), []);

        $this->assertSame('Minimal 1 item produk wajib diisi.', $errors['items']);
    }

    public function test_only_blank_rows_are_skipped_and_reported_as_incomplete(): void
    {
        $errors = $this->errors($this->header(), [['product_id' => '', 'qty_ordered' => '', 'cost_price' => ''], []]);

        $this->assertSame(['items' => 'Minimal 1 item produk yang lengkap wajib diisi.'], $errors);
    }

    public function test_blank_rows_between_valid_rows_are_ignored(): void
    {
        $po = $this->service->create($this->header(), [
            $this->line(['qty_ordered' => 2]),
            ['product_id' => '', 'qty_ordered' => '', 'cost_price' => ''],
            $this->line(['qty_ordered' => 3, 'cost_price' => '0']),
        ], $this->creator());

        $this->assertCount(2, $po->items);
        $this->assertSame(3, $po->items[1]->qtyOrdered);
        $this->assertSame(0.0, $po->items[1]->costPrice);
    }

    public function test_row_without_product_reports_product_required(): void
    {
        $errors = $this->errors($this->header(), [$this->line(['product_id' => ''])]);

        $this->assertSame(['items.0.product_id' => 'Produk wajib dipilih.', 'items' => 'Minimal 1 item produk yang lengkap wajib diisi.'], $errors);
    }

    public function test_non_numeric_product_reports_product_required(): void
    {
        $errors = $this->errors($this->header(), [$this->line(['product_id' => 'x1'])]);

        $this->assertSame('Produk wajib dipilih.', $errors['items.0.product_id']);
    }

    public function test_missing_product_reports_invalid_product(): void
    {
        $errors = $this->errors($this->header(), [$this->line(['product_id' => 9999])]);

        $this->assertSame('Produk tidak valid atau nonaktif.', $errors['items.0.product_id']);
    }

    /**
     * @dataProvider invalidQuantities
     */
    public function test_invalid_quantity_is_rejected(string $qty): void
    {
        $errors = $this->errors($this->header(), [$this->line(['qty_ordered' => $qty])]);

        $this->assertSame(['items.0.qty_ordered' => 'Qty harus bilangan bulat > 0.', 'items' => 'Minimal 1 item produk yang lengkap wajib diisi.'], $errors);
    }

    public static function invalidQuantities(): array
    {
        return ['empty' => [''], 'negative' => ['-3'], 'decimal' => ['1.5'], 'text' => ['dua'], 'zero' => ['0']];
    }

    /**
     * @dataProvider invalidPrices
     */
    public function test_invalid_cost_price_is_rejected(string $price): void
    {
        $errors = $this->errors($this->header(), [$this->line(['cost_price' => $price])]);

        $this->assertSame(['items.0.cost_price' => 'Harga beli harus angka >= 0.', 'items' => 'Minimal 1 item produk yang lengkap wajib diisi.'], $errors);
    }

    public static function invalidPrices(): array
    {
        return ['empty' => [''], 'negative' => ['-1'], 'text' => ['murah']];
    }

    public function test_errors_are_keyed_by_row_index_and_first_failing_field_wins(): void
    {
        $errors = $this->errors($this->header(), [
            $this->line(),
            $this->line(['product_id' => 9999, 'qty_ordered' => 0]),
            $this->line(['qty_ordered' => 'x', 'cost_price' => 'y']),
        ]);

        $this->assertSame([
            'items.1.product_id' => 'Produk tidak valid atau nonaktif.',
            'items.2.qty_ordered' => 'Qty harus bilangan bulat > 0.',
        ], $errors);
    }

    public function test_header_and_item_errors_are_reported_together(): void
    {
        $errors = $this->errors(['supplier_id' => '', 'warehouse_id' => '', 'order_date' => ''], [$this->line(['qty_ordered' => 0])]);

        $this->assertSame(['supplier_id', 'warehouse_id', 'order_date', 'items.0.qty_ordered', 'items'], array_keys($errors));
    }

    public function test_duplicate_products_are_currently_accepted_as_separate_lines(): void
    {
        $po = $this->service->create($this->header(), [$this->line(['qty_ordered' => 1]), $this->line(['qty_ordered' => 2])], $this->creator());

        $this->assertCount(2, $po->items);
        $this->assertSame([1, 2], array_map(static fn ($i) => $i->qtyOrdered, $po->items));
    }
}
