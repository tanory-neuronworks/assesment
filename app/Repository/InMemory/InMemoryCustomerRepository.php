<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\Customer;
use App\Repository\Contracts\CustomerRepositoryInterface;

final class InMemoryCustomerRepository implements CustomerRepositoryInterface
{
    /** @var array<int,Customer> */
    private array $customers = [];

    private int $nextId = 1;

    public function findById(int $id): ?Customer
    {
        return $this->customers[$id] ?? null;
    }

    public function all(bool $onlyActive = false): array
    {
        $all = array_values($this->customers);
        if (!$onlyActive) {
            return $all;
        }

        return array_values(array_filter($all, static fn (Customer $c) => $c->isActive));
    }

    public function create(Customer $customer): int
    {
        $id = $this->nextId++;
        $customer->id = $id;
        $this->customers[$id] = $customer;

        return $id;
    }

    public function update(Customer $customer): void
    {
        if ($customer->id === null) {
            return;
        }
        $this->customers[$customer->id] = $customer;
    }

    public function setActive(int $id, bool $active): void
    {
        if (isset($this->customers[$id])) {
            $this->customers[$id]->isActive = $active;
        }
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->customers, function (Customer $customer) use ($search): bool {
            if ($search === null || $search === '') {
                return true;
            }
            $statusLabel = $customer->isActive ? 'Aktif' : 'Nonaktif';
            $haystack = strtolower($customer->name . ' ' . $customer->contact . ' ' . $customer->address . ' ' . $statusLabel);

            return str_contains($haystack, strtolower($search));
        }));

        $total = count($items);
        $page = max(1, $page);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new Pagination($slice, $total, $page, $perPage);
    }
}
