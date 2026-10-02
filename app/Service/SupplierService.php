<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\Supplier;
use App\Repository\Contracts\SupplierRepositoryInterface;

final class SupplierService
{
    public function __construct(private readonly SupplierRepositoryInterface $suppliers)
    {
    }

    /**
     * @return Supplier[]
     */
    public function list(bool $onlyActive = false): array
    {
        return $this->suppliers->all($onlyActive);
    }

    public function paginate(?string $search, int $page, int $perPage = 10): Pagination
    {
        return $this->suppliers->paginate($search, $page, $perPage);
    }

    public function find(int $id): Supplier
    {
        $supplier = $this->suppliers->findById($id);
        if ($supplier === null) {
            throw new NotFoundException("Supplier #{$id} not found");
        }

        return $supplier;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): Supplier
    {
        $errors = $this->validate($data);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $supplier = new Supplier(
            id: null,
            name: trim((string) $data['name']),
            contact: trim((string) ($data['contact'] ?? '')),
            address: trim((string) ($data['address'] ?? '')),
            isActive: true,
        );

        $this->suppliers->create($supplier);

        return $supplier;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): Supplier
    {
        $supplier = $this->find($id);

        $errors = $this->validate($data);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $supplier->name = trim((string) $data['name']);
        $supplier->contact = trim((string) ($data['contact'] ?? ''));
        $supplier->address = trim((string) ($data['address'] ?? ''));

        $this->suppliers->update($supplier);

        return $supplier;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->find($id);
        $this->suppliers->setActive($id, $active);
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
            $errors['name'] = 'Nama supplier wajib diisi.';
        }

        return $errors;
    }
}
