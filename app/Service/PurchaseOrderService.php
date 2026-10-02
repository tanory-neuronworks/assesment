<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\User;
use App\Repository\Contracts\ProductRepositoryInterface;
use App\Repository\Contracts\PurchaseOrderRepositoryInterface;
use App\Repository\Contracts\SupplierRepositoryInterface;
use App\Repository\Contracts\WarehouseRepositoryInterface;

final class PurchaseOrderService
{
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    /**
     * @return PurchaseOrder[]
     */
    public function list(): array
    {
        return $this->purchaseOrders->all();
    }

    public function paginate(?string $search, ?PurchaseOrderStatus $status, int $page, int $perPage = 10): Pagination
    {
        return $this->purchaseOrders->paginate($search, $status, $page, $perPage);
    }

    public function find(int $id): PurchaseOrder
    {
        $po = $this->purchaseOrders->findById($id);
        if ($po === null) {
            throw new NotFoundException("Purchase order #{$id} not found");
        }

        return $po;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $itemRows
     */
    public function create(array $data, array $itemRows, User $creator): PurchaseOrder
    {
        [$errors, $items] = $this->validate($data, $itemRows);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $po = new PurchaseOrder(
            id: null,
            supplierId: (int) $data['supplier_id'],
            warehouseId: (int) $data['warehouse_id'],
            status: PurchaseOrderStatus::Ordered,
            orderDate: (string) $data['order_date'],
            createdBy: (int) $creator->id,
            items: $items,
        );

        $this->purchaseOrders->create($po);

        return $po;
    }

    /**
     * Backs the Kanban/accordion "board" view - groups the pre-summed
     * per-order rows into status columns, each holding its own supplier
     * accordions, plus page-level totals, so the view is a pure render with
     * no aggregation logic of its own. $search (PO number / supplier /
     * product name) and the $from/$to order-date range are applied here -
     * server side, like the table's AJAX search - so every total on the
     * board (stats, per-column, per-supplier) is recomputed from just the
     * matching orders rather than the full set. An empty string for $from
     * or $to (as opposed to null) means "no bound on this side" - it's how
     * the board's date filters let the user explicitly clear a default.
     *
     * @return array{
     *     stats: array{totalOrders:int,totalSuppliers:int,totalNominal:float},
     *     columns: array<int,array{status:PurchaseOrderStatus,count:int,total:float,suppliers:array<int,array{name:string,total:float,orders:array<int,array<string,mixed>>}>}>
     * }
     */
    public function board(?string $search = null, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->purchaseOrders->boardSummary();

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    'PO-' . str_pad((string) $row['id'], 5, '0', STR_PAD_LEFT),
                    $row['supplier_name'],
                    $row['product_names'],
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        if ($from !== null && $from !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['order_date'] >= $from));
        }

        if ($to !== null && $to !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['order_date'] <= $to));
        }

        $columns = [];
        foreach (PurchaseOrderStatus::cases() as $s) {
            $columns[$s->value] = ['status' => $s, 'count' => 0, 'total' => 0.0, 'suppliers' => []];
        }

        $supplierIds = [];
        $totalNominal = 0.0;

        foreach ($rows as $row) {
            $statusKey = $row['status'];
            if (!isset($columns[$statusKey])) {
                continue;
            }

            $supplierIds[$row['supplier_id']] = true;
            $totalNominal += $row['total'];

            $columns[$statusKey]['count']++;
            $columns[$statusKey]['total'] += $row['total'];

            if (!isset($columns[$statusKey]['suppliers'][$row['supplier_id']])) {
                $columns[$statusKey]['suppliers'][$row['supplier_id']] = [
                    'name' => $row['supplier_name'],
                    'total' => 0.0,
                    'orders' => [],
                ];
            }
            $columns[$statusKey]['suppliers'][$row['supplier_id']]['total'] += $row['total'];
            $columns[$statusKey]['suppliers'][$row['supplier_id']]['orders'][] = $row;
        }

        foreach ($columns as &$column) {
            uasort($column['suppliers'], static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }
        unset($column);

        return [
            'stats' => [
                'totalOrders' => count($rows),
                'totalSuppliers' => count($supplierIds),
                'totalNominal' => $totalNominal,
            ],
            'columns' => array_values($columns),
        ];
    }

    public function cancel(int $id): PurchaseOrder
    {
        $po = $this->find($id);

        if (!in_array($po->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
            throw new ValidationException(['status' => 'PO hanya bisa dibatalkan selama belum diterima penuh.']);
        }

        $this->purchaseOrders->updateStatus($id, PurchaseOrderStatus::Cancelled);

        return $this->find($id);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $itemRows
     * @return array{0: array<string,string>, 1: PurchaseOrderItem[]}
     */
    private function validate(array $data, array $itemRows): array
    {
        [$errors, $rows] = (new OrderValidator($this->products))->validate(
            $data,
            $itemRows,
            [
                ['supplier_id', 'Supplier wajib dipilih.', 'Supplier tidak valid atau nonaktif.', fn (int $id) => $this->suppliers->findById($id)],
                ['warehouse_id', 'Gudang tujuan wajib dipilih.', 'Gudang tidak valid atau nonaktif.', fn (int $id) => $this->warehouses->findById($id)],
            ],
            ['qty_ordered', 'qty_ordered'],
            ['cost_price', 'cost_price', 'Harga beli harus angka >= 0.'],
        );

        $items = array_map(static fn (array $r): PurchaseOrderItem => new PurchaseOrderItem(
            id: null,
            purchaseOrderId: 0,
            productId: (int) $r['product_id'],
            qtyOrdered: (int) $r['qty'],
            qtyReceived: 0,
            costPrice: (float) $r['price'],
        ), $rows);

        return [$errors, $items];
    }
}
