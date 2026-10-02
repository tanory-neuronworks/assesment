<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Product;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlProductRepository;

final class ProductPaginationIntegrationTest extends IntegrationTestCase
{
    private function seedProducts(): array
    {
        $categories = new MysqlCategoryRepository($this->pdo);
        $catA = $categories->create(new Category(null, 'ITEST Pag Category A ' . uniqid()));
        $catB = $categories->create(new Category(null, 'ITEST Pag Category B ' . uniqid()));

        $products = new MysqlProductRepository($this->pdo);
        $marker = uniqid();

        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $categoryId = $i <= 6 ? $catA : $catB;
            $ids[] = $products->create(new Product(
                null, "ITEST-PAG-{$marker}-" . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                "ITEST Pagination Product {$marker} {$i}", $categoryId, 'pcs', 100, 150, 10
            ));
        }

        // Give the first product enough stock to be "normal", leave the rest
        // at zero stock (below reorder_point = 10) so they read as "low".
        $stmt = $this->pdo->prepare('INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, 1, ?)');
        $stmt->execute([$ids[0], 50]);

        return ['products' => $products, 'marker' => $marker, 'catA' => $catA, 'catB' => $catB, 'ids' => $ids];
    }

    public function test_search_matches_sku_or_name(): void
    {
        $f = $this->seedProducts();

        $result = $f['products']->paginate($f['marker'], null, null, 1, 20);

        $this->assertSame(12, $result->total);
    }

    public function test_category_filter_narrows_results(): void
    {
        $f = $this->seedProducts();

        $result = $f['products']->paginate($f['marker'], $f['catA'], null, 1, 20);

        $this->assertSame(6, $result->total);
    }

    public function test_stock_status_filter_separates_low_and_normal(): void
    {
        $f = $this->seedProducts();

        $low = $f['products']->paginate($f['marker'], null, 'low', 1, 20);
        $normal = $f['products']->paginate($f['marker'], null, 'normal', 1, 20);

        $this->assertSame(11, $low->total);
        $this->assertSame(1, $normal->total);
    }

    public function test_page_size_and_total_pages_are_correct(): void
    {
        $f = $this->seedProducts();

        $page1 = $f['products']->paginate($f['marker'], null, null, 1, 10);
        $page2 = $f['products']->paginate($f['marker'], null, null, 2, 10);

        $this->assertCount(10, $page1->items);
        $this->assertCount(2, $page2->items);
        $this->assertSame(2, $page1->totalPages());
    }
}
