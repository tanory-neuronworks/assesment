<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\Category;
use App\Repository\Contracts\CategoryRepositoryInterface;

final class CategoryService
{
    public function __construct(private readonly CategoryRepositoryInterface $categories)
    {
    }

    /**
     * @return Category[]
     */
    public function list(): array
    {
        return $this->categories->all();
    }

    public function paginate(?string $search, int $page, int $perPage = 10): Pagination
    {
        return $this->categories->paginate($search, $page, $perPage);
    }

    public function find(int $id): Category
    {
        $category = $this->categories->findById($id);
        if ($category === null) {
            throw new NotFoundException("Category #{$id} not found");
        }

        return $category;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): Category
    {
        $errors = $this->validate($data, null);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $category = new Category(
            id: null,
            name: trim((string) $data['name']),
            description: trim((string) ($data['description'] ?? '')),
        );

        $this->categories->create($category);

        return $category;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): Category
    {
        $category = $this->find($id);

        $errors = $this->validate($data, $id);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $category->name = trim((string) $data['name']);
        $category->description = trim((string) ($data['description'] ?? ''));

        $this->categories->update($category);

        return $category;
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
            $errors['name'] = 'Nama kategori wajib diisi.';
        } elseif ($this->categories->nameExists($name, $excludeId)) {
            $errors['name'] = 'Nama kategori sudah ada.';
        }

        return $errors;
    }
}
