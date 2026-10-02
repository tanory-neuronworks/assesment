<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\Contracts\UserRepositoryInterface;

final class Auth
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function login(User $user): void
    {
        Session::regenerate();
        Session::set('user_id', $user->id);
    }

    public function logout(): void
    {
        Session::destroy();
    }

    public function user(): ?User
    {
        $id = Session::get('user_id');
        if ($id === null) {
            return null;
        }

        $user = $this->users->findById((int) $id);
        if ($user === null || !$user->isActive) {
            $this->logout();

            return null;
        }

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function requireLogin(): User
    {
        $user = $this->user();
        if ($user === null) {
            throw new UnauthenticatedException();
        }

        return $user;
    }

    public function requireRole(Role ...$roles): User
    {
        $user = $this->requireLogin();
        if (!in_array($user->role, $roles, true)) {
            throw new ForbiddenException();
        }

        return $user;
    }
}
