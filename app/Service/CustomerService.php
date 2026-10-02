<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\Customer;
use App\Repository\Contracts\CustomerRepositoryInterface;

final class CustomerService
{
    public function __construct(private readonly CustomerRepositoryInterface $customers)
    {
    }

    /**
     * @return Customer[]
     */
    public function list(bool $onlyActive = false): array
    {
        return $this->customers->all($onlyActive);
    }

    public function paginate(?string $search, int $page, int $perPage = 10): Pagination
    {
        return $this->customers->paginate($search, $page, $perPage);
    }

    public function find(int $id): Customer
    {
        $customer = $this->customers->findById($id);
        if ($customer === null) {
            throw new NotFoundException("Customer #{$id} not found");
        }

        return $customer;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): Customer
    {
        $errors = $this->validate($data);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $customer = new Customer(
            id: null,
            name: trim((string) $data['name']),
            contact: trim((string) ($data['contact'] ?? '')),
            address: trim((string) ($data['address'] ?? '')),
            isActive: true,
        );

        $this->customers->create($customer);

        return $customer;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): Customer
    {
        $customer = $this->find($id);

        $errors = $this->validate($data);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $customer->name = trim((string) $data['name']);
        $customer->contact = trim((string) ($data['contact'] ?? ''));
        $customer->address = trim((string) ($data['address'] ?? ''));

        $this->customers->update($customer);

        return $customer;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->find($id);
        $this->customers->setActive($id, $active);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Nama customer wajib diisi.';
        }

        return $errors;
    }
}
