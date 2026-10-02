<?php

declare(strict_types=1);

namespace App\Entity;

final class User
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $username,
        public string $email,
        public string $passwordHash,
        public Role $role,
        public bool $isActive = true,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }
}
