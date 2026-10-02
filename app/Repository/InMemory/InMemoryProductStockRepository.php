<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Entity\ProductStock;
use App\Repository\Contracts\ProductStockRepositoryInterface;

final class InMemoryProductStockRepository implements ProductStockRepositoryInterface
{
    /** @var ProductStock[] */
    private array $stocks = [];

    public function seed(ProductStock $stock): void
    {
        $this->stocks[] = $stock;
    }

    public function findByProduct(int $productId): array
    {
        return array_values(array_filter($this->stocks, static fn (ProductStock $s) => $s->productId === $productId));
    }

    public function totalForProduct(int $productId): int
    {
        return array_sum(array_map(
            static fn (ProductStock $s) => $s->quantity,
            $this->findByProduct($productId)
        ));
    }

    public function totalsForProducts(array $productIds): array
    {
        $totals = [];
        foreach ($productIds as $productId) {
            $totals[$productId] = $this->totalForProduct($productId);
        }

        return $totals;
    }

    public function incrementStock(int $productId, int $warehouseId, int $quantity): void
    {
        foreach ($this->stocks as $stock) {
            if ($stock->productId === $productId && $stock->warehouseId === $warehouseId) {
                $stock->quantity += $quantity;

                return;
            }
        }

        $this->stocks[] = new ProductStock($productId, $warehouseId, $quantity);
    }

    public function decrementStockIfSufficient(int $productId, int $warehouseId, int $quantity): bool
    {
        foreach ($this->stocks as $stock) {
            if ($stock->productId === $productId && $stock->warehouseId === $warehouseId) {
                if ($stock->quantity < $quantity) {
                    return false;
                }
                $stock->quantity -= $quantity;

                return true;
            }
        }

        return false;
    }
}
