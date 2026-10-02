<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrderItem
{
    public function __construct(
        public ?int $id,
        public int $salesOrderId,
        public int $productId,
        public int $qty,
        public float $sellPrice,
        public ?string $productName = null,
        public ?string $productSku = null,
        public ?string $unit = null,
        public ?string $productImage = null,
    ) {
    }
}
