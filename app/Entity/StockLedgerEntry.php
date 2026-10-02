<?php

declare(strict_types=1);

namespace App\Entity;

final class StockLedgerEntry
{
    public function __construct(
        public ?int $id,
        public int $productId,
        public int $warehouseId,
        public StockMovementType $movementType,
        public int $quantity,
        public ReferenceType $referenceType,
        public ?int $referenceId,
        public int $performedBy,
        public ?string $warehouseName = null,
        public ?string $performedByName = null,
        public ?string $createdAt = null,
        public ?string $productName = null,
        public ?string $productSku = null,
    ) {
    }
}
