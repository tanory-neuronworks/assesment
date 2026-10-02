<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Entity\User;
use App\Repository\Contracts\CustomerRepositoryInterface;
use App\Repository\Contracts\ProductRepositoryInterface;
use App\Repository\Contracts\SalesOrderRepositoryInterface;
use App\Repository\Contracts\WarehouseRepositoryInterface;

final class SalesOrderService
{
    public function __construct(
        private readonly SalesOrderRepositoryInterface $salesOrders,
        private readonly CustomerRepositoryInterface $customers,
        private readonly WarehouseRepositoryInterface $warehouses,
        private readonly ProductRepositoryInterface $products,
    ) {
    }

    /**
     * @return SalesOrder[]
     */
    public function list(User $viewer): array
    {
        if ($viewer->role === Role::Sales) {
            return $this->salesOrders->all($viewer->id);
        }

        return $this->salesOrders->all();
    }

    public function paginate(?string $search, ?SalesOrderStatus $status, User $viewer, int $page, int $perPage = 10): Pagination
    {
        $createdBy = $viewer->role === Role::Sales ? $viewer->id : null;

        return $this->salesOrders->paginate($search, $status, $createdBy, $page, $perPage);
    }

    /**
     * Backs the Kanban/accordion "board" view - same role scoping as
     * list()/paginate() (Sales only sees their own orders). Pre-groups the
     * pre-summed per-order rows into status columns, each holding its own
     * customer accordions, plus page-level totals, so the view is a pure
     * render with no aggregation logic of its own.
     *
     * $search (SO number / customer / product name, same as the table's
     * search) and the $from/$to order-date range are applied here - server
     * side, like the table's AJAX search - so every total on the board
     * (stats, per-column, per-customer) is recomputed from just the
     * matching orders rather than the full set. An empty string for $from
     * or $to (as opposed to null) means "no bound on this side" - it's how
     * the board's date filters let the user explicitly clear a default.
     *
     * @return array{
     *     stats: array{totalOrders:int,totalCustomers:int,totalNominal:float},
     *     columns: array<int,array{status:SalesOrderStatus,count:int,total:float,customers:array<int,array{name:string,total:float,orders:array<int,array<string,mixed>>}>}>
     * }
     */
    public function board(User $viewer, ?string $search = null, ?string $from = null, ?string $to = null): array
    {
        $createdBy = $viewer->role === Role::Sales ? $viewer->id : null;
        $rows = $this->salesOrders->boardSummary($createdBy);

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', [
                    'SO-' . str_pad((string) $row['id'], 5, '0', STR_PAD_LEFT),
                    $row['customer_name'],
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
        foreach (SalesOrderStatus::cases() as $s) {
            $columns[$s->value] = ['status' => $s, 'count' => 0, 'total' => 0.0, 'customers' => []];
        }

        $customerIds = [];
        $totalNominal = 0.0;

        foreach ($rows as $row) {
            $statusKey = $row['status'];
            if (!isset($columns[$statusKey])) {
                continue;
            }

            $customerIds[$row['customer_id']] = true;
            $totalNominal += $row['total'];

            $columns[$statusKey]['count']++;
            $columns[$statusKey]['total'] += $row['total'];

            if (!isset($columns[$statusKey]['customers'][$row['customer_id']])) {
                $columns[$statusKey]['customers'][$row['customer_id']] = [
                    'name' => $row['customer_name'],
                    'total' => 0.0,
                    'orders' => [],
                ];
            }
            $columns[$statusKey]['customers'][$row['customer_id']]['total'] += $row['total'];
            $columns[$statusKey]['customers'][$row['customer_id']]['orders'][] = $row;
        }

        foreach ($columns as &$column) {
            uasort($column['customers'], static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }
        unset($column);

        return [
            'stats' => [
                'totalOrders' => count($rows),
                'totalCustomers' => count($customerIds),
                'totalNominal' => $totalNominal,
            ],
            'columns' => array_values($columns),
        ];
    }

    public function find(int $id): SalesOrder
    {
        $so = $this->salesOrders->findById($id);
        if ($so === null) {
            throw new NotFoundException("Sales order #{$id} not found");
        }

        return $so;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $itemRows
     */
    public function create(array $data, array $itemRows, User $creator): SalesOrder
    {
        [$errors, $items] = $this->validate($data, $itemRows);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $so = new SalesOrder(
            id: null,
            customerId: (int) $data['customer_id'],
            warehouseId: (int) $data['warehouse_id'],
            status: SalesOrderStatus::Draft,
            orderDate: (string) $data['order_date'],
            createdBy: (int) $creator->id,
            items: $items,
        );

        $this->salesOrders->create($so);

        return $so;
    }

    public function submit(int $id, User $requester): SalesOrder
    {
        $so = $this->find($id);

        if ($so->status !== SalesOrderStatus::Draft) {
            throw new ValidationException(['status' => 'SO hanya bisa diajukan dari status Draft.']);
        }

        if ($requester->role !== Role::Admin && $requester->id !== $so->createdBy) {
            throw new ValidationException(['status' => 'Anda tidak berwenang mengajukan SO ini.']);
        }

        $this->salesOrders->updateStatus($id, SalesOrderStatus::PendingApproval);

        return $this->find($id);
    }

    public function approve(int $id, User $approver): SalesOrder
    {
        $this->guardApprovable($id, $approver);
        $this->salesOrders->approve($id, (int) $approver->id);

        return $this->find($id);
    }

    public function reject(int $id, User $approver): SalesOrder
    {
        $this->guardApprovable($id, $approver);
        $this->salesOrders->updateStatus($id, SalesOrderStatus::Cancelled);

        return $this->find($id);
    }

    public function cancel(int $id, User $requester): SalesOrder
    {
        $so = $this->find($id);

        $cancellable = [SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval, SalesOrderStatus::Approved];
        if (!in_array($so->status, $cancellable, true)) {
            throw new ValidationException(['status' => 'SO tidak bisa dibatalkan pada status ini.']);
        }

        if ($requester->role !== Role::Admin && $requester->id !== $so->createdBy) {
            throw new ValidationException(['status' => 'Anda tidak berwenang membatalkan SO ini.']);
        }

        $this->salesOrders->updateStatus($id, SalesOrderStatus::Cancelled);

        return $this->find($id);
    }

    /**
     * Approve/reject share the same guards: must be PendingApproval, and -
     * this is BR-04, segregation of duties - the approver can never be the
     * order's own creator, regardless of role.
     */
    private function guardApprovable(int $id, User $approver): SalesOrder
    {
        $so = $this->find($id);

        if ($so->status !== SalesOrderStatus::PendingApproval) {
            throw new ValidationException(['status' => 'SO tidak dalam status menunggu persetujuan.']);
        }

        if ($approver->id === $so->createdBy) {
            throw new ValidationException(['approval' => 'Pembuat SO tidak boleh menyetujui/menolak order miliknya sendiri.']);
        }

        return $so;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $itemRows
     * @return array{0: array<string,string>, 1: SalesOrderItem[]}
     */
    private function validate(array $data, array $itemRows): array
    {
        [$errors, $rows] = (new OrderValidator($this->products))->validate(
            $data,
            $itemRows,
            [
                ['customer_id', 'Customer wajib dipilih.', 'Customer tidak valid atau nonaktif.', fn (int $id) => $this->customers->findById($id)],
                ['warehouse_id', 'Gudang asal wajib dipilih.', 'Gudang tidak valid atau nonaktif.', fn (int $id) => $this->warehouses->findById($id)],
            ],
            ['qty', 'qty'],
            ['sell_price', 'sell_price', 'Harga jual harus angka >= 0.'],
        );

        $items = array_map(static fn (array $r): SalesOrderItem => new SalesOrderItem(
            id: null,
            salesOrderId: 0,
            productId: (int) $r['product_id'],
            qty: (int) $r['qty'],
            sellPrice: (float) $r['price'],
        ), $rows);

        return [$errors, $items];
    }
}
