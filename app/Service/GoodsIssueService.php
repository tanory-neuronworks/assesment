<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Entity\ReferenceType;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderStatus;
use App\Entity\StockLedgerEntry;
use App\Entity\StockMovementType;
use App\Entity\User;
use App\Repository\Contracts\ProductStockRepositoryInterface;
use App\Repository\Contracts\SalesOrderRepositoryInterface;
use App\Repository\Contracts\StockLedgerRepositoryInterface;
use App\Repository\Contracts\TransactionManagerInterface;

/**
 * Orchestrates the ARCH-02/BR-09 transaction for goods issue: every line
 * must have enough stock or NONE of them are mutated (SPEC has no
 * PartiallyFulfilled status for SO, unlike PO). The concurrency guarantee
 * comes entirely from ProductStockRepositoryInterface::decrementStockIfSufficient
 * being one atomic conditional UPDATE per line - there is no separate
 * "check stock" step here that a race could slip through.
 */
final class GoodsIssueService
{
    public function __construct(
        private readonly TransactionManagerInterface $transactions,
        private readonly SalesOrderRepositoryInterface $salesOrders,
        private readonly ProductStockRepositoryInterface $productStocks,
        private readonly StockLedgerRepositoryInterface $stockLedger,
    ) {
    }

    public function issue(int $soId, User $performer): SalesOrder
    {
        $so = $this->salesOrders->findById($soId);
        if ($so === null) {
            throw new NotFoundException("Sales order #{$soId} not found");
        }

        if ($so->status !== SalesOrderStatus::Approved) {
            throw new ValidationException(['status' => 'Goods issue hanya bisa diproses untuk SO berstatus Approved.']);
        }

        if ($so->items === []) {
            throw new ValidationException(['items' => 'SO tidak memiliki item.']);
        }

        return $this->transactions->transactional(function () use ($so, $performer, $soId) {
            foreach ($so->items as $item) {
                if (!$this->productStocks->decrementStockIfSufficient($item->productId, $so->warehouseId, $item->qty)) {
                    throw new ValidationException([
                        'stock' => "Stok tidak cukup untuk produk {$item->productSku} ({$item->productName}).",
                    ]);
                }

                $this->stockLedger->create(new StockLedgerEntry(
                    id: null,
                    productId: $item->productId,
                    warehouseId: $so->warehouseId,
                    movementType: StockMovementType::Issue,
                    quantity: $item->qty,
                    referenceType: ReferenceType::SalesOrder,
                    referenceId: $so->id,
                    performedBy: (int) $performer->id,
                ));
            }

            $this->salesOrders->updateStatus($soId, SalesOrderStatus::Fulfilled);

            $result = $this->salesOrders->findById($soId);
            if ($result === null) {
                throw new NotFoundException("Sales order #{$soId} not found");
            }

            return $result;
        });
    }
}
