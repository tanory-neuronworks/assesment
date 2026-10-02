<?php

declare(strict_types=1);

namespace App\Core;

final class Pagination
{
    /**
     * @param mixed[] $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->totalPages();
    }

    public static function normalizePage(?string $rawPage): int
    {
        $page = (int) $rawPage;

        return $page < 1 ? 1 : $page;
    }

    /**
     * @param int[] $allowed
     */
    public static function normalizePerPage(?string $rawPerPage, array $allowed = [10, 25, 50], int $default = 10): int
    {
        $perPage = (int) $rawPerPage;

        return in_array($perPage, $allowed, true) ? $perPage : $default;
    }
}
