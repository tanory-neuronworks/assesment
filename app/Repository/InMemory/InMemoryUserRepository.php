<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\User;
use App\Repository\Contracts\UserRepositoryInterface;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int,User> */
    private array $users = [];

    private int $nextId = 1;

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function findByEmail(string $email): ?User
    {
        foreach ($this->users as $user) {
            if (strcasecmp($user->email, $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function findByUsername(string $username): ?User
    {
        foreach ($this->users as $user) {
            if (strcasecmp($user->username, $username) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->users);
    }

    public function paginate(?string $search, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->users, function (User $user) use ($search): bool {
            if ($search === null || $search === '') {
                return true;
            }
            $statusLabel = $user->isActive ? 'Aktif' : 'Nonaktif';
            $haystack = strtolower($user->name . ' ' . $user->username . ' ' . $user->email . ' ' . $user->role->label() . ' ' . $statusLabel);

            return str_contains($haystack, strtolower($search));
        }));

        usort($items, static fn (User $a, User $b): int => $a->name <=> $b->name);

        $total = count($items);
        $page = max(1, $page);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new Pagination($slice, $total, $page, $perPage);
    }

    public function create(User $user): int
    {
        $id = $this->nextId++;
        $user->id = $id;
        $this->users[$id] = $user;

        return $id;
    }

    public function update(User $user): void
    {
        if ($user->id === null) {
            return;
        }
        $this->users[$user->id] = $user;
    }

    public function setActive(int $id, bool $active): void
    {
        if (isset($this->users[$id])) {
            $this->users[$id]->isActive = $active;
        }
    }
}
