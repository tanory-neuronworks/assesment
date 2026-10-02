<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\Category;
use App\Repository\Contracts\CategoryRepositoryInterface;

final class InMemoryCategoryRepository implements CategoryRepositoryInterface
{
    /** @var array<int,Category> */
    private array $categories = [];

    private int $nextId = 1;

    public function findById(int $id): ?Category
    {
        return $this->categories[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->categories);
    }

    public function create(Category $category): int
    {
        $id = $this->nextId++;
        $category->id = $id;
        $this->categories[$id] = $category;

        return $id;
    }

    public function update(Category $category): void
    {
        if ($category->id === null) {
            return;
        }
        $this->categories[$category->id] = $category;
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        foreach ($this->categories as $category) {
            if ($category->id === $excludeId) {
                continue;
            }
            if (strcasecmp($category->name, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->categories, function (Category $category) use ($search): bool {
            if ($search === null || $search === '') {
                return true;
            }
            $haystack = strtolower($category->name . ' ' . $category->description);

            return str_contains($haystack, strtolower($search));
        }));

        $total = count($items);
        $page = max(1, $page);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new Pagination($slice, $total, $page, $perPage);
    }
}
