<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrderItem
{
    public function __construct(
        public ?int $id,
        public int $purchaseOrderId,
        public int $productId,
        public int $qtyOrdered,
        public int $qtyReceived,
        public float $costPrice,
        public ?string $productName = null,
        public ?string $productSku = null,
        public ?string $unit = null,
        public ?string $productImage = null,
    ) {
    }

    public function remaining(): int
    {
        return $this->qtyOrdered - $this->qtyReceived;
    }
}
