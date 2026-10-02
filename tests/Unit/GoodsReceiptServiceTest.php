<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\InMemory\InMemoryProductStockRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\NullTransactionManager;
use App\Service\GoodsReceiptService;
use PHPUnit\Framework\TestCase;

final class GoodsReceiptServiceTest extends TestCase
{
    private function makeService(InMemoryPurchaseOrderRepository $orders, InMemoryProductStockRepository $stocks, InMemoryStockLedgerRepository $ledger): GoodsReceiptService
    {
        return new GoodsReceiptService(new NullTransactionManager(), $orders, $stocks, $ledger);
    }

    private function seedPo(InMemoryPurchaseOrderRepository $orders, PurchaseOrderStatus $status, int $qtyOrdered, int $qtyReceived = 0): PurchaseOrder
    {
        $po = new PurchaseOrder(
            id: null,
            supplierId: 1,
            warehouseId: 1,
            status: $status,
            orderDate: '2026-09-01',
            createdBy: 1,
            items: [new PurchaseOrderItem(null, 0, 99, $qtyOrdered, $qtyReceived, 1000)],
        );
        $orders->create($po);

        return $po;
    }

    private function performer(): User
    {
        return new User(4, 'Warehouse Staff', 'whstaff', 'wh@test.local', 'hash', Role::WarehouseStaff, true);
    }

    public function test_partial_receive_updates_status_and_stock(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $ledger = new InMemoryStockLedgerRepository();
        $po = $this->seedPo($orders, PurchaseOrderStatus::Ordered, 20);
        $itemId = $po->items[0]->id;

        $updated = $this->makeService($orders, $stocks, $ledger)->receive($po->id, [$itemId => 8], $this->performer());

        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $updated->status);
        $this->assertSame(8, $stocks->totalForProduct(99));
        $this->assertCount(1, $ledger->findByProduct(99));
    }

    public function test_receiving_the_remaining_quantity_marks_po_received(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $ledger = new InMemoryStockLedgerRepository();
        $po = $this->seedPo($orders, PurchaseOrderStatus::PartiallyReceived, 20, 12);
        $itemId = $po->items[0]->id;

        $updated = $this->makeService($orders, $stocks, $ledger)->receive($po->id, [$itemId => 8], $this->performer());

        $this->assertSame(PurchaseOrderStatus::Received, $updated->status);
    }

    public function test_it_rejects_receiving_more_than_remaining(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $ledger = new InMemoryStockLedgerRepository();
        $po = $this->seedPo($orders, PurchaseOrderStatus::Ordered, 10);
        $itemId = $po->items[0]->id;

        $this->expectException(ValidationException::class);
        $this->makeService($orders, $stocks, $ledger)->receive($po->id, [$itemId => 11], $this->performer());
    }

    public function test_it_rejects_receiving_against_cancelled_po(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $ledger = new InMemoryStockLedgerRepository();
        $po = $this->seedPo($orders, PurchaseOrderStatus::Cancelled, 10);
        $itemId = $po->items[0]->id;

        $this->expectException(ValidationException::class);
        $this->makeService($orders, $stocks, $ledger)->receive($po->id, [$itemId => 5], $this->performer());
    }

    public function test_receiving_nothing_is_rejected(): void
    {
        $orders = new InMemoryPurchaseOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $ledger = new InMemoryStockLedgerRepository();
        $po = $this->seedPo($orders, PurchaseOrderStatus::Ordered, 10);
        $itemId = $po->items[0]->id;

        $this->expectException(ValidationException::class);
        $this->makeService($orders, $stocks, $ledger)->receive($po->id, [$itemId => 0], $this->performer());
    }
}
