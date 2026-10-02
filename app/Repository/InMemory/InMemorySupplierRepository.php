<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\Supplier;
use App\Repository\Contracts\SupplierRepositoryInterface;

final class InMemorySupplierRepository implements SupplierRepositoryInterface
{
    /** @var array<int,Supplier> */
    private array $suppliers = [];

    private int $nextId = 1;

    public function findById(int $id): ?Supplier
    {
        return $this->suppliers[$id] ?? null;
    }

    public function all(bool $onlyActive = false): array
    {
        $all = array_values($this->suppliers);
        if (!$onlyActive) {
            return $all;
        }

        return array_values(array_filter($all, static fn (Supplier $s) => $s->isActive));
    }

    public function create(Supplier $supplier): int
    {
        $id = $this->nextId++;
        $supplier->id = $id;
        $this->suppliers[$id] = $supplier;

        return $id;
    }

    public function update(Supplier $supplier): void
    {
        if ($supplier->id === null) {
            return;
        }
        $this->suppliers[$supplier->id] = $supplier;
    }

    public function setActive(int $id, bool $active): void
    {
        if (isset($this->suppliers[$id])) {
            $this->suppliers[$id]->isActive = $active;
        }
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->suppliers, function (Supplier $supplier) use ($search): bool {
            if ($search === null || $search === '') {
                return true;
            }
            $statusLabel = $supplier->isActive ? 'Aktif' : 'Nonaktif';
            $haystack = strtolower($supplier->name . ' ' . $supplier->contact . ' ' . $supplier->address . ' ' . $statusLabel);

            return str_contains($haystack, strtolower($search));
        }));

        $total = count($items);
        $page = max(1, $page);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new Pagination($slice, $total, $page, $perPage);
    }
}
