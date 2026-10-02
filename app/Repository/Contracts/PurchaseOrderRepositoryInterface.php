<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderStatus;

interface PurchaseOrderRepositoryInterface
{
    public function findById(int $id): ?PurchaseOrder;

    /**
     * @return PurchaseOrder[] header rows only (no items), most recent first
     */
    public function all(): array;

    /**
     * Persists the PO header and its items (already set on $po->items) in one
     * transaction, and sets the generated ids back on $po and each item.
     */
    public function create(PurchaseOrder $po): int;

    public function updateStatus(int $id, PurchaseOrderStatus $status): void;

    /**
     * Atomically increases qty_received, refusing (returns false) if doing so
     * would exceed qty_ordered - this is the concurrency guard for ARCH-02.
     */
    public function incrementItemReceivedQty(int $itemId, int $qty): bool;

    /**
     * @return array<string,int> PurchaseOrderStatus value => count
     */
    public function countByStatus(): array;

    /**
     * @return PurchaseOrder[] header rows only, order_date between $from and $to inclusive
     */
    public function findInRange(string $from, string $to): array;

    public function paginate(?string $search, ?PurchaseOrderStatus $status, int $page, int $perPage): Pagination;

    /**
     * One aggregated row per PO (supplier name + item total pre-summed,
     * unlike all()/paginate() which return header-only rows) - backs the
     * Kanban/accordion "board" view, which groups by status then supplier
     * and needs each order's nominal total up front rather than fetching
     * items per order. product_names is a comma-joined list of the
     * distinct products on the order, backing the board's search (matches
     * PO number, supplier, or product name).
     *
     * @return array<int,array{id:int,supplier_id:int,supplier_name:string,status:string,order_date:string,created_by_name:string,total:float,product_names:string}>
     */
    public function boardSummary(): array;
}
