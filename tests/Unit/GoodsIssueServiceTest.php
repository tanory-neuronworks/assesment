<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Entity\ProductStock;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Entity\User;
use App\Repository\InMemory\InMemoryProductStockRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\NullTransactionManager;
use App\Service\GoodsIssueService;
use PHPUnit\Framework\TestCase;

final class GoodsIssueServiceTest extends TestCase
{
    private function makeService(InMemorySalesOrderRepository $orders, InMemoryProductStockRepository $stocks, InMemoryStockLedgerRepository $ledger): GoodsIssueService
    {
        return new GoodsIssueService(new NullTransactionManager(), $orders, $stocks, $ledger);
    }

    private function performer(): User
    {
        return new User(4, 'Warehouse Staff', 'whstaff', 'wh@test.local', 'hash', Role::WarehouseStaff, true);
    }

    public function test_issue_decrements_stock_across_all_lines_and_marks_fulfilled(): void
    {
        $orders = new InMemorySalesOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $stocks->seed(new ProductStock(101, 1, 20));
        $stocks->seed(new ProductStock(102, 1, 10));
        $ledger = new InMemoryStockLedgerRepository();

        $so = new SalesOrder(null, 1, 1, SalesOrderStatus::Approved, '2026-09-01', 2, items: [
            new SalesOrderItem(null, 0, 101, 8, 1000),
            new SalesOrderItem(null, 0, 102, 5, 2000),
        ]);
        $orders->create($so);

        $updated = $this->makeService($orders, $stocks, $ledger)->issue($so->id, $this->performer());

        $this->assertSame(SalesOrderStatus::Fulfilled, $updated->status);
        $this->assertSame(12, $stocks->totalForProduct(101));
        $this->assertSame(5, $stocks->totalForProduct(102));
        $this->assertCount(1, $ledger->findByProduct(101));
        $this->assertCount(1, $ledger->findByProduct(102));
    }

    public function test_it_rejects_issue_when_stock_is_insufficient(): void
    {
        $orders = new InMemorySalesOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $stocks->seed(new ProductStock(201, 1, 3));
        $ledger = new InMemoryStockLedgerRepository();

        $so = new SalesOrder(null, 1, 1, SalesOrderStatus::Approved, '2026-09-01', 2, items: [
            new SalesOrderItem(null, 0, 201, 10, 1000),
        ]);
        $orders->create($so);

        try {
            $this->makeService($orders, $stocks, $ledger)->issue($so->id, $this->performer());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertSame(3, $stocks->totalForProduct(201));
        $this->assertCount(0, $ledger->findByProduct(201));
    }

    public function test_it_rejects_issue_against_a_non_approved_so(): void
    {
        $orders = new InMemorySalesOrderRepository();
        $stocks = new InMemoryProductStockRepository();
        $ledger = new InMemoryStockLedgerRepository();

        $so = new SalesOrder(null, 1, 1, SalesOrderStatus::PendingApproval, '2026-09-01', 2, items: [
            new SalesOrderItem(null, 0, 301, 1, 1000),
        ]);
        $orders->create($so);

        $this->expectException(ValidationException::class);
        $this->makeService($orders, $stocks, $ledger)->issue($so->id, $this->performer());
    }
}
