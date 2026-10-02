<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\Category;

interface CategoryRepositoryInterface
{
    public function findById(int $id): ?Category;

    /**
     * @return Category[]
     */
    public function all(): array;

    public function create(Category $category): int;

    public function update(Category $category): void;

    public function nameExists(string $name, ?int $excludeId = null): bool;

    public function paginate(?string $search, int $page, int $perPage): Pagination;
}
