<?php

declare(strict_types=1);

namespace App\Repository\InMemory;

use App\Core\Pagination;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderStatus;
use App\Repository\Contracts\SalesOrderRepositoryInterface;

final class InMemorySalesOrderRepository implements SalesOrderRepositoryInterface
{
    /** @var array<int,SalesOrder> */
    private array $orders = [];

    private int $nextOrderId = 1;

    private int $nextItemId = 1;

    public function findById(int $id): ?SalesOrder
    {
        return $this->orders[$id] ?? null;
    }

    public function all(?int $createdBy = null): array
    {
        $all = array_values($this->orders);
        if ($createdBy === null) {
            return $all;
        }

        return array_values(array_filter($all, static fn (SalesOrder $so) => $so->createdBy === $createdBy));
    }

    public function create(SalesOrder $so): int
    {
        $id = $this->nextOrderId++;
        $so->id = $id;

        foreach ($so->items as $item) {
            $item->id = $this->nextItemId++;
            $item->salesOrderId = $id;
        }

        $this->orders[$id] = $so;

        return $id;
    }

    public function updateStatus(int $id, SalesOrderStatus $status): void
    {
        if (isset($this->orders[$id])) {
            $this->orders[$id]->status = $status;
        }
    }

    public function approve(int $id, int $approvedBy): void
    {
        if (isset($this->orders[$id])) {
            $this->orders[$id]->status = SalesOrderStatus::Approved;
            $this->orders[$id]->approvedBy = $approvedBy;
        }
    }

    public function countByStatus(?int $createdBy = null): array
    {
        $counts = [];
        foreach ($this->all($createdBy) as $so) {
            $counts[$so->status->value] = ($counts[$so->status->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function findInRange(string $from, string $to, ?int $createdBy = null): array
    {
        return array_values(array_filter(
            $this->all($createdBy),
            static fn (SalesOrder $so) => $so->orderDate >= $from && $so->orderDate <= $to
        ));
    }

    public function paginate(?string $search, ?SalesOrderStatus $status, ?int $createdBy, int $page, int $perPage): Pagination
    {
        $items = array_values(array_filter($this->all($createdBy), function (SalesOrder $so) use ($search, $status) {
            if ($status !== null && $so->status !== $status) {
                return false;
            }
            if ($search !== null && $search !== '') {
                $formattedId = 'SO-' . str_pad((string) $so->id, 5, '0', STR_PAD_LEFT);
                $haystack = implode(' ', [
                    $formattedId,
                    (string) $so->customerName,
                    (string) $so->warehouseName,
                    $so->orderDate,
                    (string) $so->createdByName,
                    $so->status->label(),
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

    public function boardSummary(?int $createdBy = null): array
    {
        return array_map(static function (SalesOrder $so): array {
            $total = 0.0;
            $productNames = [];
            foreach ($so->items as $item) {
                $total += $item->qty * $item->sellPrice;
                if ($item->productName !== null) {
                    $productNames[$item->productName] = true;
                }
            }

            return [
                'id' => (int) $so->id,
                'customer_id' => $so->customerId,
                'customer_name' => (string) ($so->customerName ?? ''),
                'status' => $so->status->value,
                'order_date' => $so->orderDate,
                'created_by_name' => (string) ($so->createdByName ?? ''),
                'total' => $total,
                'product_names' => implode(', ', array_keys($productNames)),
            ];
        }, $this->all($createdBy));
    }
}
