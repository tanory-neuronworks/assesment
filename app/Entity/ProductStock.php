<?php

declare(strict_types=1);

namespace App\Entity;

final class ProductStock
{
    public function __construct(
        public int $productId,
        public int $warehouseId,
        public int $quantity,
        public ?string $warehouseName = null,
    ) {
    }
}
