<?php

declare(strict_types=1);

namespace App\Repository\Contracts;

use App\Core\Pagination;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderStatus;

interface SalesOrderRepositoryInterface
{
    public function findById(int $id): ?SalesOrder;

    /**
     * @return SalesOrder[] header rows only (no items), most recent first
     */
    public function all(?int $createdBy = null): array;

    /**
     * Persists the SO header and its items (already set on $so->items) in
     * one transaction, and sets the generated ids back on $so and each item.
     */
    public function create(SalesOrder $so): int;

    public function updateStatus(int $id, SalesOrderStatus $status): void;

    /**
     * Sets status=Approved and approved_by in one write.
     */
    public function approve(int $id, int $approvedBy): void;

    /**
     * @return array<string,int> SalesOrderStatus value => count
     */
    public function countByStatus(?int $createdBy = null): array;

    /**
     * @return SalesOrder[] header rows only, order_date between $from and $to inclusive
     */
    public function findInRange(string $from, string $to, ?int $createdBy = null): array;

    public function paginate(?string $search, ?SalesOrderStatus $status, ?int $createdBy, int $page, int $perPage): Pagination;

    /**
     * One aggregated row per SO (customer name + item total pre-summed,
     * unlike all()/paginate() which return header-only rows) - backs the
     * Kanban/accordion "board" view, which groups by status then customer
     * and needs each order's nominal total up front rather than fetching
     * items per order. product_names is a comma-joined list of the
     * distinct products on the order, backing the board's client-side
     * search (matches SO number, customer, or product name).
     *
     * @return array<int,array{id:int,customer_id:int,customer_name:string,status:string,order_date:string,created_by_name:string,total:float,product_names:string}>
     */
    public function boardSummary(?int $createdBy = null): array;
}
