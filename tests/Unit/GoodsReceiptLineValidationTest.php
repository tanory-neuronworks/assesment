<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\OperationFailedException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderItem;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\Contracts\PurchaseOrderRepositoryInterface;
use App\Repository\InMemory\InMemoryProductStockRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemoryStockLedgerRepository;
use App\Repository\InMemory\NullTransactionManager;
use App\Service\GoodsReceiptService;
use PHPUnit\Framework\TestCase;

final class GoodsReceiptLineValidationTest extends TestCase
{
    private InMemoryPurchaseOrderRepository $orders;
    private InMemoryProductStockRepository $stocks;
    private InMemoryStockLedgerRepository $ledger;

    protected function setUp(): void
    {
        $this->orders = new InMemoryPurchaseOrderRepository();
        $this->stocks = new InMemoryProductStockRepository();
        $this->ledger = new InMemoryStockLedgerRepository();
    }

    private function service(?PurchaseOrderRepositoryInterface $orders = null): GoodsReceiptService
    {
        return new GoodsReceiptService(new NullTransactionManager(), $orders ?? $this->orders, $this->stocks, $this->ledger);
    }

    /** Two-item PO: item A (product 11) ordered 10, item B (product 22) ordered 5 with 2 already received. */
    private function seedPo(PurchaseOrderStatus $status = PurchaseOrderStatus::Ordered): PurchaseOrder
    {
        $po = new PurchaseOrder(null, 1, 1, $status, '2026-09-01', 1, [
            new PurchaseOrderItem(null, 0, 11, 10, 0, 1000),
            new PurchaseOrderItem(null, 0, 22, 5, 2, 2000),
        ]);
        $this->orders->create($po);

        return $po;
    }

    private function performer(): User
    {
        return new User(4, 'Warehouse Staff', 'whstaff', 'wh@test.local', 'hash', Role::WarehouseStaff, true);
    }

    /** @return array<string,string> */
    private function errorsFor(int $poId, array $lines): array
    {
        try {
            $this->service()->receive($poId, $lines, $this->performer());
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('Expected ValidationException');
    }

    private function assertNothingReceived(PurchaseOrder $po): void
    {
        $this->assertSame(0, $this->stocks->totalForProduct(11));
        $this->assertSame(0, $this->stocks->totalForProduct(22));
        $this->assertSame([], $this->ledger->findByProduct(11));
        $this->assertSame(0, $po->items[0]->qtyReceived);
        $this->assertSame(2, $po->items[1]->qtyReceived);
    }

    public function test_unknown_po_throws_not_found(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Purchase order #999 not found');

        $this->service()->receive(999, [1 => 1], $this->performer());
    }

    public function test_po_in_wrong_status_reports_status_error(): void
    {
        $po = $this->seedPo(PurchaseOrderStatus::Received);

        $errors = $this->errorsFor($po->id, [$po->items[0]->id => 1]);

        $this->assertSame(['status' => 'PO tidak dalam status yang bisa menerima barang.'], $errors);
    }

    public function test_empty_lines_report_minimum_one_qty(): void
    {
        $po = $this->seedPo();

        $errors = $this->errorsFor($po->id, []);

        $this->assertSame(['items' => 'Isi minimal satu qty penerimaan.'], $errors);
        $this->assertNothingReceived($po);
    }

    public function test_all_zero_and_negative_quantities_report_minimum_one_qty(): void
    {
        $po = $this->seedPo();

        $errors = $this->errorsFor($po->id, [$po->items[0]->id => 0, $po->items[1]->id => -4]);

        $this->assertSame(['items' => 'Isi minimal satu qty penerimaan.'], $errors);
        $this->assertNothingReceived($po);
    }

    public function test_non_numeric_quantity_is_cast_to_zero_and_ignored(): void
    {
        $po = $this->seedPo();

        $errors = $this->errorsFor($po->id, [$po->items[0]->id => 'abc']);

        $this->assertSame(['items' => 'Isi minimal satu qty penerimaan.'], $errors);
    }

    public function test_quantity_above_remaining_reports_remaining_in_message(): void
    {
        $po = $this->seedPo();
        $itemB = $po->items[1]->id;

        $errors = $this->errorsFor($po->id, [$itemB => 4]);

        $this->assertSame('Qty melebihi sisa yang belum diterima (sisa: 3).', $errors["items.{$itemB}"]);
        $this->assertNothingReceived($po);
    }

    public function test_unknown_item_id_reports_item_not_found(): void
    {
        $po = $this->seedPo();

        $errors = $this->errorsFor($po->id, [9999 => 1]);

        $this->assertSame('Item tidak ditemukan pada PO ini.', $errors['items.9999']);
        // Nothing valid remained, so the generic summary key is added as well.
        $this->assertSame('Isi minimal satu qty penerimaan.', $errors['items']);
    }

    public function test_item_from_another_po_is_rejected_as_not_found(): void
    {
        $po = $this->seedPo();
        $other = $this->seedPo();
        $foreign = $other->items[0]->id;

        $errors = $this->errorsFor($po->id, [$foreign => 1]);

        $this->assertSame('Item tidak ditemukan pada PO ini.', $errors["items.{$foreign}"]);
    }

    public function test_one_invalid_line_aborts_whole_receipt_even_if_another_is_valid(): void
    {
        $po = $this->seedPo();
        [$a, $b] = [$po->items[0]->id, $po->items[1]->id];

        $errors = $this->errorsFor($po->id, [$a => 5, $b => 99]);

        $this->assertSame(["items.{$b}"], array_keys($errors));
        $this->assertNothingReceived($po);
    }

    public function test_errors_are_collected_per_invalid_line(): void
    {
        $po = $this->seedPo();
        [$a, $b] = [$po->items[0]->id, $po->items[1]->id];

        $errors = $this->errorsFor($po->id, [$a => 11, $b => 4, 777 => 1]);

        $this->assertStringContainsString('sisa: 10', $errors["items.{$a}"]);
        $this->assertStringContainsString('sisa: 3', $errors["items.{$b}"]);
        $this->assertSame('Item tidak ditemukan pada PO ini.', $errors['items.777']);
    }

    public function test_zero_lines_are_skipped_while_valid_lines_are_received(): void
    {
        $po = $this->seedPo();
        [$a, $b] = [$po->items[0]->id, $po->items[1]->id];

        $result = $this->service()->receive($po->id, [$a => 0, $b => 3], $this->performer());

        $this->assertSame(0, $this->stocks->totalForProduct(11));
        $this->assertSame(3, $this->stocks->totalForProduct(22));
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $result->status);
        $this->assertSame(5, $result->items[1]->qtyReceived);
    }

    public function test_receiving_all_items_fully_marks_po_received_and_writes_ledger(): void
    {
        $po = $this->seedPo();
        [$a, $b] = [$po->items[0]->id, $po->items[1]->id];

        $result = $this->service()->receive($po->id, [$a => 10, $b => 3], $this->performer());

        $this->assertSame(PurchaseOrderStatus::Received, $result->status);
        $this->assertSame(10, $this->stocks->totalForProduct(11));
        $this->assertSame(3, $this->stocks->totalForProduct(22));
        $entries = $this->ledger->findByProduct(22);
        $this->assertCount(1, $entries);
        $this->assertSame(3, $entries[0]->quantity);
        $this->assertSame(4, $entries[0]->performedBy);
    }

    public function test_lost_concurrent_update_throws_operation_failed(): void
    {
        $po = $this->seedPo();
        $itemId = $po->items[0]->id;

        // Simulates another process taking the remaining quantity between
        // validation and the guarded UPDATE: the atomic increment refuses.
        $racing = new class ($this->orders) implements PurchaseOrderRepositoryInterface {
            public function __construct(private readonly InMemoryPurchaseOrderRepository $inner)
            {
            }

            public function findById(int $id): ?PurchaseOrder
            {
                return $this->inner->findById($id);
            }

            public function all(): array
            {
                return $this->inner->all();
            }

            public function create(PurchaseOrder $po): int
            {
                return $this->inner->create($po);
            }

            public function updateStatus(int $id, PurchaseOrderStatus $status): void
            {
                $this->inner->updateStatus($id, $status);
            }

            public function incrementItemReceivedQty(int $itemId, int $qty): bool
            {
                return false;
            }

            public function countByStatus(): array
            {
                return $this->inner->countByStatus();
            }

            public function findInRange(string $from, string $to): array
            {
                return $this->inner->findInRange($from, $to);
            }

            public function paginate(?string $search, ?PurchaseOrderStatus $status, int $page, int $perPage): Pagination
            {
                return $this->inner->paginate($search, $status, $page, $perPage);
            }

            public function boardSummary(): array
            {
                return $this->inner->boardSummary();
            }
        };

        try {
            $this->service($racing)->receive($po->id, [$itemId => 4], $this->performer());
            $this->fail('Expected OperationFailedException');
        } catch (OperationFailedException $e) {
            $this->assertSame('Item PO sudah diterima penuh oleh proses lain, silakan muat ulang halaman.', $e->getMessage());
        }

        $this->assertSame(PurchaseOrderStatus::Ordered, $po->status);
        $this->assertSame(0, $po->items[0]->qtyReceived);
    }

    public function test_repository_rejects_stale_increment_beyond_ordered(): void
    {
        $po = $this->seedPo();
        $itemId = $po->items[1]->id;

        $this->assertTrue($this->orders->incrementItemReceivedQty($itemId, 3));
        $this->assertFalse($this->orders->incrementItemReceivedQty($itemId, 1));
        $this->assertSame(5, $po->items[1]->qtyReceived);
    }
}
