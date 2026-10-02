<?php

declare(strict_types=1);

namespace App\Entity;

final class Warehouse
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $location,
        public bool $isActive = true,
    ) {
    }
}
