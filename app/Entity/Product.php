<?php

declare(strict_types=1);

namespace App\Entity;

final class Product
{
    public function __construct(
        public ?int $id,
        public string $sku,
        public string $name,
        public int $categoryId,
        public string $unit,
        public float $costPrice,
        public float $sellPrice,
        public int $reorderPoint,
        public ?string $image = null,
        public bool $isActive = true,
        public ?string $categoryName = null,
    ) {
    }
}
