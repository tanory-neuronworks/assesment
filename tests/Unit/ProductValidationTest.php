<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Entity\Category;
use App\Repository\InMemory\InMemoryCategoryRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryProductStockRepository;
use App\Service\ProductService;
use PHPUnit\Framework\TestCase;

final class ProductValidationTest extends TestCase
{
    private function makeService(): ProductService
    {
        $categories = new InMemoryCategoryRepository();
        $categories->create(new Category(null, 'Elektronik'));

        return new ProductService(
            new InMemoryProductRepository(),
            $categories,
            new InMemoryProductStockRepository(),
        );
    }

    public function test_it_rejects_missing_sku(): void
    {
        $service = $this->makeService();

        try {
            $service->create([
                'sku' => '',
                'name' => 'Produk A',
                'category_id' => 1,
                'unit' => 'pcs',
                'cost_price' => 10,
                'sell_price' => 20,
                'reorder_point' => 5,
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->errors());
        }
    }

    public function test_it_rejects_duplicate_sku(): void
    {
        $service = $this->makeService();
        $payload = [
            'sku' => 'SKU-001',
            'name' => 'Produk A',
            'category_id' => 1,
            'unit' => 'pcs',
            'cost_price' => 10,
            'sell_price' => 20,
            'reorder_point' => 5,
        ];

        $service->create($payload);

        try {
            $service->create($payload);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->errors());
        }
    }

    public function test_it_rejects_negative_reorder_point(): void
    {
        $service = $this->makeService();

        try {
            $service->create([
                'sku' => 'SKU-002',
                'name' => 'Produk B',
                'category_id' => 1,
                'unit' => 'pcs',
                'cost_price' => 10,
                'sell_price' => 20,
                'reorder_point' => -5,
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reorder_point', $e->errors());
        }
    }

    public function test_it_creates_product_when_data_is_valid(): void
    {
        $service = $this->makeService();

        $product = $service->create([
            'sku' => 'SKU-003',
            'name' => 'Produk C',
            'category_id' => 1,
            'unit' => 'pcs',
            'cost_price' => 10,
            'sell_price' => 20,
            'reorder_point' => 5,
        ]);

        $this->assertSame('SKU-003', $product->sku);
        $this->assertTrue($product->isActive);
    }
}
