<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\Contracts\UserRepositoryInterface;

final class AuthService
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * $identifier may be either the user's email or their username - the
     * login form has a single "Email/Username" field rather than asking
     * the user to know which one they're typing.
     */
    public function attempt(string $identifier, string $password): ?User
    {
        $identifier = trim($identifier);
        $user = str_contains($identifier, '@')
            ? $this->users->findByEmail($identifier)
            : $this->users->findByUsername($identifier);

        // A username-shaped input might still legitimately be someone's
        // email local-part typed without the domain, or vice versa - but
        // more importantly, don't assume the '@' heuristic is exhaustive;
        // fall back to the other lookup before giving up.
        if ($user === null) {
            $user = str_contains($identifier, '@')
                ? $this->users->findByUsername($identifier)
                : $this->users->findByEmail($identifier);
        }

        if ($user === null || !$user->isActive) {
            return null;
        }

        if (!password_verify($password, $user->passwordHash)) {
            return null;
        }

        return $user;
    }
}
