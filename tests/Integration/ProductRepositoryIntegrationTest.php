<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Product;
use App\Repository\Mysql\MysqlCategoryRepository;
use App\Repository\Mysql\MysqlProductRepository;
use PDOException;

final class ProductRepositoryIntegrationTest extends IntegrationTestCase
{
    private function seedCategory(): int
    {
        $categories = new MysqlCategoryRepository($this->pdo);

        return $categories->create(new Category(null, 'Integration Test Category ' . uniqid()));
    }

    public function test_it_enforces_unique_sku_constraint(): void
    {
        $categoryId = $this->seedCategory();
        $products = new MysqlProductRepository($this->pdo);
        $sku = 'ITEST-' . uniqid();

        $products->create(new Product(null, $sku, 'Product One', $categoryId, 'pcs', 10, 20, 5));

        $this->expectException(PDOException::class);
        $products->create(new Product(null, $sku, 'Product Two', $categoryId, 'pcs', 10, 20, 5));
    }

    public function test_deactivated_product_is_excluded_from_active_listing(): void
    {
        $categoryId = $this->seedCategory();
        $products = new MysqlProductRepository($this->pdo);
        $sku = 'ITEST-' . uniqid();

        $id = $products->create(new Product(null, $sku, 'Product To Deactivate', $categoryId, 'pcs', 10, 20, 5));
        $products->setActive($id, false);

        $activeSkus = array_map(static fn (Product $p) => $p->sku, $products->all(onlyActive: true));
        $allSkus = array_map(static fn (Product $p) => $p->sku, $products->all(onlyActive: false));

        $this->assertNotContains($sku, $activeSkus);
        $this->assertContains($sku, $allSkus);
    }

    public function test_it_rejects_negative_quantity_at_database_level(): void
    {
        $categoryId = $this->seedCategory();
        $products = new MysqlProductRepository($this->pdo);
        $productId = $products->create(new Product(null, 'ITEST-' . uniqid(), 'Product With Stock', $categoryId, 'pcs', 10, 20, 5));

        $stmt = $this->pdo->prepare('INSERT INTO warehouses (name, location) VALUES (?, ?)');
        $stmt->execute(['Integration Test Warehouse ' . uniqid(), 'Nowhere']);
        $warehouseId = (int) $this->pdo->lastInsertId();

        $this->expectException(PDOException::class);
        $insert = $this->pdo->prepare('INSERT INTO product_stocks (product_id, warehouse_id, quantity) VALUES (?, ?, ?)');
        $insert->execute([$productId, $warehouseId, -1]);
    }
}
