<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Repository\Contracts\PurchaseOrderRepositoryInterface;

final class InMemoryPurchaseOrderRepository implements PurchaseOrderRepositoryInterface
{
    /** @var array<int,PurchaseOrder> */
    private array $orders = [];

    /** @var array<int,PurchaseOrderItem> */
    private array $items = [];

    private int $nextOrderId = 1;

    private int $nextItemId = 1;

    public function findById(int $id): ?PurchaseOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function all(): array
    {
        return array_values($this->orders);
    }

    public function create(PurchaseOrder $po): int
    {
        $id = $this->nextOrderId++;
        $po->id = $id;

        foreach ($po->items as $item) {
            $item->id = $this->nextItemId++;
            $item->purchaseOrderId = $id;
            $this->items[$item->id] = $item;
        }

        $this->orders[$id] = $po;

        return $id;
    }

    public function updateStatus(int $id, PurchaseOrderStatus $status): void
    {
        if (isset($this->orders[$id])) {
            $this->orders[$id]->status = $status;
        }
    }

    public function incrementItemReceivedQty(int $itemId, int $qty): bool
    {
        $item = $this->items[$itemId] ?? null;
        if ($item === null || $item->qtyReceived + $qty > $item->qtyOrdered) {
            return false;
        }

        $item->qtyReceived += $qty;

        return true;
    }

    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->orders as $po) {
            $counts[$po->status->value] = ($counts[$po->status->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function findInRange(string $from, string $to): array
    {
        return array_values(array_filter(
            $this->orders,
            static fn (PurchaseOrder $po) => $po->orderDate >= $from && $po->orderDate <= $to
        ));
    }

    public function paginate(?string $search, ?PurchaseOrderStatus $status, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->orders, function (PurchaseOrder $po) use ($search, $status) {
            if ($status !== null && $po->status !== $status) {
                return false;
            }
            if ($search !== null && $search !== '') {
                $formattedId = 'PO-' . str_pad((string) $po->id, 5, '0', STR_PAD_LEFT);
                $haystack = implode(' ', [
                    $formattedId,
                    (string) $po->supplierName,
                    (string) $po->warehouseName,
                    $po->orderDate,
                    (string) $po->createdByName,
                    $po->status->label(),
                ]);
                if (!str_contains(strtolower($haystack), strtolower($search))) {
                    return false;
                }
            }

            return true;
        }));

        $total = count($items);
        $page = max(1, $page);
        $slice = array_slice($items, ($page - 1) * $perPage, $perPage);

        return new Pagination($slice, $total, $page, $perPage);
    }

    public function boardSummary(): array
    {
        return array_map(static function (PurchaseOrder $po): array {
            $total = 0.0;
            $productNames = [];
            foreach ($po->items as $item) {
                $total += $item->qtyOrdered * $item->costPrice;
                if ($item->productName !== null) {
                    $productNames[$item->productName] = true;
                }
            }

            return [
                'id' => (int) $po->id,
                'supplier_id' => $po->supplierId,
                'supplier_name' => (string) ($po->supplierName ?? ''),
                'status' => $po->status->value,
                'order_date' => $po->orderDate,
                'created_by_name' => (string) ($po->createdByName ?? ''),
                'total' => $total,
                'product_names' => implode(', ', array_keys($productNames)),
            ];
        }, $this->all());
    }
}
