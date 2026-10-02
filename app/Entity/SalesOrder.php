<?php

declare(strict_types=1);

namespace App\Entity;

final class SalesOrder
{
    /**
     * @param SalesOrderItem[] $items
     */
    public function __construct(
        public ?int $id,
        public int $customerId,
        public int $warehouseId,
        public SalesOrderStatus $status,
        public string $orderDate,
        public int $createdBy,
        public ?int $approvedBy = null,
        public array $items = [],
        public ?string $customerName = null,
        public ?string $warehouseName = null,
        public ?string $createdByName = null,
        public ?string $approvedByName = null,
        public ?string $createdAt = null,
    ) {
    }
}
