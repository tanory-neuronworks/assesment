<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\OperationFailedException;
use App\Core\Exceptions\ValidationException;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\ReferenceType;
use App\Entity\StockLedgerEntry;
use App\Entity\StockMovementType;
use App\Entity\User;
use App\Repository\Contracts\ProductStockRepositoryInterface;
use App\Repository\Contracts\PurchaseOrderRepositoryInterface;
use App\Repository\Contracts\StockLedgerRepositoryInterface;
use App\Repository\Contracts\TransactionManagerInterface;

/**
 * Orchestrates the ARCH-02 transaction for goods receipt: a stock increase,
 * a ledger entry, and the PO item's received-quantity update must all
 * succeed together or not at all, via TransactionManagerInterface so this
 * stays testable without a real DB connection (PdoTransactionManager in
 * production, NullTransactionManager backing the InMemory repos in tests).
 */
final class GoodsReceiptService
{
    public function __construct(
        private readonly TransactionManagerInterface $transactions,
        private readonly PurchaseOrderRepositoryInterface $purchaseOrders,
        private readonly ProductStockRepositoryInterface $productStocks,
        private readonly StockLedgerRepositoryInterface $stockLedger,
    ) {
    }

    /**
     * @param array<int,int> $lines itemId => qty to receive now
     * @param array<int,PurchaseOrderItem> $itemsById
     * @return array<int,int> itemId => validated qty
     */
    private function validateLines(array $lines, array $itemsById): array
    {
        $errors = [];
        $toReceive = [];
        foreach ($lines as $itemId => $qty) {
            $qty = (int) $qty;
            if ($qty <= 0) {
                continue;
            }

            $error = $this->lineError($itemsById[$itemId] ?? null, $qty);
            if ($error !== null) {
                $errors["items.{$itemId}"] = $error;
                continue;
            }

            $toReceive[$itemId] = $qty;
        }

        if ($toReceive === []) {
            $errors['items'] = $errors['items'] ?? 'Isi minimal satu qty penerimaan.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $toReceive;
    }

    private function lineError(?PurchaseOrderItem $item, int $qty): ?string
    {
        if ($item === null) {
            return 'Item tidak ditemukan pada PO ini.';
        }

        if ($qty > $item->remaining()) {
            return "Qty melebihi sisa yang belum diterima (sisa: {$item->remaining()}).";
        }

        return null;
    }

    /**
     * @param array<int,int> $lines itemId => qty to receive now
     */
    public function receive(int $poId, array $lines, User $performer): PurchaseOrder
    {
        $po = $this->purchaseOrders->findById($poId);
        if ($po === null) {
            throw new NotFoundException("Purchase order #{$poId} not found");
        }

        if (!in_array($po->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived], true)) {
            throw new ValidationException(['status' => 'PO tidak dalam status yang bisa menerima barang.']);
        }

        $itemsById = [];
        foreach ($po->items as $item) {
            $itemsById[$item->id] = $item;
        }

        $toReceive = $this->validateLines($lines, $itemsById);

        return $this->transactions->transactional(function () use ($toReceive, $itemsById, $po, $performer, $poId) {
            foreach ($toReceive as $itemId => $qty) {
                $item = $itemsById[$itemId];

                $this->productStocks->incrementStock($item->productId, $po->warehouseId, $qty);

                $this->stockLedger->create(new StockLedgerEntry(
                    id: null,
                    productId: $item->productId,
                    warehouseId: $po->warehouseId,
                    movementType: StockMovementType::Receipt,
                    quantity: $qty,
                    referenceType: ReferenceType::PurchaseOrder,
                    referenceId: $po->id,
                    performedBy: (int) $performer->id,
                ));

                if (!$this->purchaseOrders->incrementItemReceivedQty($itemId, $qty)) {
                    throw new OperationFailedException('Item PO sudah diterima penuh oleh proses lain, silakan muat ulang halaman.');
                }
            }

            $updated = $this->purchaseOrders->findById($poId);
            if ($updated === null) {
                throw new NotFoundException("Purchase order #{$poId} not found");
            }
            $newStatus = $updated->isFullyReceived() ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived;
            $this->purchaseOrders->updateStatus($poId, $newStatus);

            $result = $this->purchaseOrders->findById($poId);
            if ($result === null) {
                throw new NotFoundException("Purchase order #{$poId} not found");
            }

            return $result;
        });
    }
}
