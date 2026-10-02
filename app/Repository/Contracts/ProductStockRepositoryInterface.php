<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Entity\ProductStock;

interface ProductStockRepositoryInterface
{
    /**
     * @return ProductStock[] one row per warehouse for the given product
     */
    public function findByProduct(int $productId): array;

    public function totalForProduct(int $productId): int;

    /**
     * @return array<int,int> productId => total quantity across all warehouses
     */
    public function totalsForProducts(array $productIds): array;

    /**
     * Atomically increase stock for product+warehouse (insert the row if it
     * doesn't exist yet). Must not read-then-write to stay concurrency-safe.
     */
    public function incrementStock(int $productId, int $warehouseId, int $quantity): void;

    /**
     * Atomically decrease stock only if enough is available - this single
     * conditional statement IS the oversell guard (ARCH-02/BR-09), not a
     * separate read-then-write check. Returns false (no mutation) if the
     * current quantity is less than $quantity.
     */
    public function decrementStockIfSufficient(int $productId, int $warehouseId, int $quantity): bool;
}
