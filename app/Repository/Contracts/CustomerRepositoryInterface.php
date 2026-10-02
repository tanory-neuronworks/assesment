<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\Customer;

interface CustomerRepositoryInterface
{
    public function findById(int $id): ?Customer;

    /**
     * @return Customer[]
     */
    public function all(bool $onlyActive = false): array;

    public function create(Customer $customer): int;

    public function update(Customer $customer): void;

    public function setActive(int $id, bool $active): void;

    public function paginate(?string $search, int $page, int $perPage): Pagination;
}
