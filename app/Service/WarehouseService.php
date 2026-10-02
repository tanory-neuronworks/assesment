<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\Warehouse;
use App\Repository\Contracts\WarehouseRepositoryInterface;

final class WarehouseService
{
    public function __construct(private readonly WarehouseRepositoryInterface $warehouses)
    {
    }

    /**
     * @return Warehouse[]
     */
    public function list(bool $onlyActive = false): array
    {
        return $this->warehouses->all($onlyActive);
    }

    public function paginate(?string $search, int $page, int $perPage = 10): Pagination
    {
        return $this->warehouses->paginate($search, $page, $perPage);
    }

    public function find(int $id): Warehouse
    {
        $warehouse = $this->warehouses->findById($id);
        if ($warehouse === null) {
            throw new NotFoundException("Warehouse #{$id} not found");
        }

        return $warehouse;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): Warehouse
    {
        $errors = $this->validate($data, null);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $warehouse = new Warehouse(
            id: null,
            name: trim((string) $data['name']),
            location: trim((string) $data['location']),
            isActive: true,
        );

        $this->warehouses->create($warehouse);

        return $warehouse;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): Warehouse
    {
        $warehouse = $this->find($id);

        $errors = $this->validate($data, $id);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $warehouse->name = trim((string) $data['name']);
        $warehouse->location = trim((string) $data['location']);

        $this->warehouses->update($warehouse);

        return $warehouse;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->find($id);
        $this->warehouses->setActive($id, $active);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data, ?int $excludeId): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Nama gudang wajib diisi.';
        } elseif ($this->warehouses->nameExists($name, $excludeId)) {
            $errors['name'] = 'Nama gudang sudah ada.';
        }

        $location = trim((string) ($data['location'] ?? ''));
        if ($location === '') {
            $errors['location'] = 'Lokasi wajib diisi.';
        }

        return $errors;
    }
}
