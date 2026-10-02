<?php

declare(strict_types=1);

namespace App\Entity;

final class Customer
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $contact,
        public string $address,
        public bool $isActive = true,
    ) {
    }
}
