<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\Product;
use App\Repository\Contracts\ProductRepositoryInterface;

final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int,Product> */
    private array $products = [];

    /**
     * Test-only: total stock per product id, used by lowStockCount() /
     * inventoryValue() / paginate()'s stock-status filter. Defaults to 0 for
     * any product not explicitly seeded here - this fake has no notion of
     * warehouses, unlike InMemoryProductStockRepository.
     *
     * @var array<int,int>
     */
    private array $stockOverrides = [];

    private int $nextId = 1;

    public function seedStock(int $productId, int $quantity): void
    {
        $this->stockOverrides[$productId] = $quantity;
    }

    public function findById(int $id): ?Product
    {
        return $this->products[$id] ?? null;
    }

    public function findBySku(string $sku): ?Product
    {
        foreach ($this->products as $product) {
            if (strcasecmp($product->sku, $sku) === 0) {
                return $product;
            }
        }

        return null;
    }

    public function all(bool $onlyActive = false): array
    {
        $all = array_values($this->products);
        if (!$onlyActive) {
            return $all;
        }

        return array_values(array_filter($all, static fn (Product $p) => $p->isActive));
    }

    public function create(Product $product): int
    {
        $id = $this->nextId++;
        $product->id = $id;
        $this->products[$id] = $product;

        return $id;
    }

    public function update(Product $product): void
    {
        if ($product->id === null) {
            return;
        }
        $this->products[$product->id] = $product;
    }

    public function setActive(int $id, bool $active): void
    {
        if (isset($this->products[$id])) {
            $this->products[$id]->isActive = $active;
        }
    }

    public function skuExists(string $sku, ?int $excludeId = null): bool
    {
        foreach ($this->products as $product) {
            if ($product->id === $excludeId) {
                continue;
            }
            if (strcasecmp($product->sku, $sku) === 0) {
                return true;
            }
        }

        return false;
    }

    public function lowStockCount(): int
    {
        $count = 0;
        foreach ($this->products as $product) {
            if (!$product->isActive) {
                continue;
            }
            if (($this->stockOverrides[$product->id] ?? 0) < $product->reorderPoint) {
                $count++;
            }
        }

        return $count;
    }

    public function inventoryValue(): float
    {
        $value = 0.0;
        foreach ($this->products as $product) {
            $value += ($this->stockOverrides[$product->id] ?? 0) * $product->costPrice;
        }

        return $value;
    }

    private function matchesSearch(Product $p, int $stock, ?string $search): bool
    {
        if ($search === null || $search === '') {
            return true;
        }

        $haystack = implode(' ', [
            $p->sku,
            $p->name,
            $p->categoryName ?? '',
            (string) $p->sellPrice,
            (string) $stock,
            $p->isActive ? 'Aktif' : 'Nonaktif',
        ]);

        return str_contains(strtolower($haystack), strtolower($search));
    }

    private function matchesStockStatus(Product $p, int $stock, ?string $stockStatus): bool
    {
        return match ($stockStatus) {
            'low' => $stock < $p->reorderPoint,
            'normal' => $stock >= $p->reorderPoint,
            default => true,
        };
    }

    public function paginate(?string $search, ?int $categoryId, ?string $stockStatus, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->products, function (Product $p) use ($search, $categoryId, $stockStatus) {
            $stock = $this->stockOverrides[$p->id] ?? 0;

            return $this->matchesSearch($p, $stock, $search)
                && ($categoryId === null || $p->categoryId === $categoryId)
                && $this->matchesStockStatus($p, $stock, $stockStatus);
        }));

        $total = count($items);
        $page = max(1, $page);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new Pagination($slice, $total, $page, $perPage);
    }
}
