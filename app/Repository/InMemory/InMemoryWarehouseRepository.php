<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\Warehouse;
use App\Repository\Contracts\WarehouseRepositoryInterface;

final class InMemoryWarehouseRepository implements WarehouseRepositoryInterface
{
    /** @var array<int,Warehouse> */
    private array $warehouses = [];

    private int $nextId = 1;

    public function findById(int $id): ?Warehouse
    {
        return $this->warehouses[$id] ?? null;
    }

    public function all(bool $onlyActive = false): array
    {
        $all = array_values($this->warehouses);
        if (!$onlyActive) {
            return $all;
        }

        return array_values(array_filter($all, static fn (Warehouse $w) => $w->isActive));
    }

    public function create(Warehouse $warehouse): int
    {
        $id = $this->nextId++;
        $warehouse->id = $id;
        $this->warehouses[$id] = $warehouse;

        return $id;
    }

    public function update(Warehouse $warehouse): void
    {
        if ($warehouse->id === null) {
            return;
        }
        $this->warehouses[$warehouse->id] = $warehouse;
    }

    public function setActive(int $id, bool $active): void
    {
        if (isset($this->warehouses[$id])) {
            $this->warehouses[$id]->isActive = $active;
        }
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        foreach ($this->warehouses as $warehouse) {
            if ($warehouse->id === $excludeId) {
                continue;
            }
            if (strcasecmp($warehouse->name, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->warehouses, function (Warehouse $warehouse) use ($search): bool {
            if ($search === null || $search === '') {
                return true;
            }
            $statusLabel = $warehouse->isActive ? 'Aktif' : 'Nonaktif';
            $haystack = strtolower($warehouse->name . ' ' . $warehouse->location . ' ' . $statusLabel);

            return str_contains($haystack, strtolower($search));
        }));

        $total = count($items);
        $page = max(1, $page);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new Pagination($slice, $total, $page, $perPage);
    }
}
