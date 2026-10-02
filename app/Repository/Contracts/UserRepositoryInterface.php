<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\User;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function findByUsername(string $username): ?User;

    /**
     * @return User[]
     */
    public function all(): array;

    public function paginate(?string $search, int $page, int $perPage): Pagination;

    public function create(User $user): int;

    public function update(User $user): void;

    public function setActive(int $id, bool $active): void;
}
