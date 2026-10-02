<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\Product;

interface ProductRepositoryInterface
{
    public function findById(int $id): ?Product;

    public function findBySku(string $sku): ?Product;

    /**
     * @return Product[]
     */
    public function all(bool $onlyActive = false): array;

    public function create(Product $product): int;

    public function update(Product $product): void;

    public function setActive(int $id, bool $active): void;

    public function skuExists(string $sku, ?int $excludeId = null): bool;

    /**
     * Count of active products whose total stock (across all warehouses) is
     * below their reorder point - used by both the dashboard and JOB-01.
     */
    public function lowStockCount(): int;

    /**
     * Sum of quantity * cost_price across all product_stocks rows.
     */
    public function inventoryValue(): float;

    /**
     * @param 'low'|'normal'|null $stockStatus
     */
    public function paginate(?string $search, ?int $categoryId, ?string $stockStatus, int $page, int $perPage): Pagination;
}
