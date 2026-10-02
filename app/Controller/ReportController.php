<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csv;
use App\Core\Request;
use App\Entity\Role;
use App\Repository\Contracts\PurchaseOrderRepositoryInterface;
use App\Repository\Contracts\SalesOrderRepositoryInterface;
use App\Repository\Contracts\StockLedgerRepositoryInterface;

final class ReportController extends Controller
{
    public function __construct(
        Auth $auth,
        private readonly StockLedgerRepositoryInterface $stockLedger,
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly SalesOrderRepositoryInterface $salesOrders,
    ) {
        parent::__construct($auth);
    }

    public function stockLedger(Request $request): string
    {
        $this->auth->requireRole(Role::Admin, Role::WarehouseStaff);
        [$from, $to] = $this->resolveRange($request);

        $rows = (function () use ($from, $to) {
            foreach ($this->stockLedger->findInRange($from, $to) as $entry) {
                yield [
                    $entry->createdAt,
                    $entry->productSku,
                    $entry->productName,
                    $entry->warehouseName,
                    $entry->movementType->value,
                    $entry->quantity,
                    $entry->referenceType->value,
                    $entry->referenceId,
                    $entry->performedByName,
                ];
            }
        })();

        return Csv::stream(
            "stock-ledger_{$from}_{$to}.csv",
            ['Waktu', 'SKU', 'Produk', 'Gudang', 'Tipe', 'Qty', 'Referensi Tipe', 'Referensi ID', 'Oleh'],
            $rows
        );
    }

    public function orders(Request $request): string
    {
        $user = $this->auth->requireRole(Role::Admin, Role::Sales);
        [$from, $to] = $this->resolveRange($request);

        $createdBy = $user->role === Role::Sales ? $user->id : null;

        $rows = (function () use ($from, $to, $createdBy, $user) {
            if ($user->role === Role::Admin) {
                foreach ($this->purchaseOrders->findInRange($from, $to) as $po) {
                    yield ['PO', $po->id, $po->orderDate, $po->supplierName, $po->status->value, $po->createdByName];
                }
            }
            foreach ($this->salesOrders->findInRange($from, $to, $createdBy) as $so) {
                yield ['SO', $so->id, $so->orderDate, $so->customerName, $so->status->value, $so->createdByName];
            }
        })();

        return Csv::stream(
            "orders_{$from}_{$to}.csv",
            ['Tipe', 'No', 'Tanggal', 'Pihak Terkait', 'Status', 'Dibuat Oleh'],
            $rows
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveRange(Request $request): array
    {
        $to = $request->query('to') ?: date('Y-m-d');
        $from = $request->query('from') ?: date('Y-m-d', strtotime('-30 days'));

        return [$from, $to];
    }
}
