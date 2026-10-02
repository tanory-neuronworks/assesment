<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\Warehouse;

interface WarehouseRepositoryInterface
{
    public function findById(int $id): ?Warehouse;

    /**
     * @return Warehouse[]
     */
    public function all(bool $onlyActive = false): array;

    public function create(Warehouse $warehouse): int;

    public function update(Warehouse $warehouse): void;

    public function setActive(int $id, bool $active): void;

    public function nameExists(string $name, ?int $excludeId = null): bool;

    public function paginate(?string $search, int $page, int $perPage): Pagination;
}
