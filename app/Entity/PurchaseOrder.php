<?php

declare(strict_types=1);

namespace App\Entity;

final class PurchaseOrder
{
    /**
     * @param PurchaseOrderItem[] $items
     */
    public function __construct(
        public ?int $id,
        public int $supplierId,
        public int $warehouseId,
        public PurchaseOrderStatus $status,
        public string $orderDate,
        public int $createdBy,
        public array $items = [],
        public ?string $supplierName = null,
        public ?string $warehouseName = null,
        public ?string $createdByName = null,
        public ?string $createdAt = null,
    ) {
    }

    public function isFullyReceived(): bool
    {
        foreach ($this->items as $item) {
            if ($item->remaining() > 0) {
                return false;
            }
        }

        return true;
    }

    public function hasAnyReceived(): bool
    {
        foreach ($this->items as $item) {
            if ($item->qtyReceived > 0) {
                return true;
            }
        }

        return false;
    }
}
