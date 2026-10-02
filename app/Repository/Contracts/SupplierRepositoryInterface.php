<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\Supplier;

interface SupplierRepositoryInterface
{
    public function findById(int $id): ?Supplier;

    /**
     * @return Supplier[]
     */
    public function all(bool $onlyActive = false): array;

    public function create(Supplier $supplier): int;

    public function update(Supplier $supplier): void;

    public function setActive(int $id, bool $active): void;

    public function paginate(?string $search, int $page, int $perPage): Pagination;
}
