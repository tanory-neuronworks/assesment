<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Entity\User;
use App\Repository\InMemory\InMemoryCustomerRepository;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Repository\InMemory\InMemoryWarehouseRepository;
use App\Service\SalesOrderService;
use PHPUnit\Framework\TestCase;

/**
 * Covers BR-04 (segregation of duties): the creator of a Sales Order can
 * never be its own approver, no exceptions - this is enforced in the
 * service layer independently of the Admin-only role guard at the
 * controller (Auth::requireRole), which is already covered generically by
 * AuthorizationTest.
 */
final class SalesOrderApprovalTest extends TestCase
{
    private function makeService(): array
    {
        $orders = new InMemorySalesOrderRepository();
        $service = new SalesOrderService(
            $orders,
            new InMemoryCustomerRepository(),
            new InMemoryWarehouseRepository(),
            new InMemoryProductRepository(),
        );

        return [$service, $orders];
    }

    private function seedSo(InMemorySalesOrderRepository $orders, SalesOrderStatus $status, int $createdBy): SalesOrder
    {
        $so = new SalesOrder(
            id: null,
            customerId: 1,
            warehouseId: 1,
            status: $status,
            orderDate: '2026-09-01',
            createdBy: $createdBy,
            items: [new SalesOrderItem(null, 0, 1, 5, 1000)],
        );
        $orders->create($so);

        return $so;
    }

    private function user(int $id, Role $role): User
    {
        return new User($id, 'User ' . $id, "user{$id}", "user{$id}@test.local", 'hash', $role, true);
    }

    public function test_creator_cannot_approve_their_own_so_even_as_admin(): void
    {
        [$service, $orders] = $this->makeService();
        $creator = $this->user(1, Role::Admin);
        $so = $this->seedSo($orders, SalesOrderStatus::PendingApproval, (int) $creator->id);

        try {
            $service->approve($so->id, $creator);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approval', $e->errors());
        }
    }

    public function test_a_different_admin_can_approve(): void
    {
        [$service, $orders] = $this->makeService();
        $creatorId = 2;
        $approver = $this->user(1, Role::Admin);
        $so = $this->seedSo($orders, SalesOrderStatus::PendingApproval, $creatorId);

        $updated = $service->approve($so->id, $approver);

        $this->assertSame(SalesOrderStatus::Approved, $updated->status);
        $this->assertSame(1, $updated->approvedBy);
    }

    public function test_it_rejects_approving_a_non_pending_so(): void
    {
        [$service, $orders] = $this->makeService();
        $approver = $this->user(1, Role::Admin);
        $so = $this->seedSo($orders, SalesOrderStatus::Draft, 2);

        $this->expectException(ValidationException::class);
        $service->approve($so->id, $approver);
    }

    public function test_reject_transitions_directly_to_cancelled(): void
    {
        [$service, $orders] = $this->makeService();
        $approver = $this->user(1, Role::Admin);
        $so = $this->seedSo($orders, SalesOrderStatus::PendingApproval, 2);

        $updated = $service->reject($so->id, $approver);

        $this->assertSame(SalesOrderStatus::Cancelled, $updated->status);
    }

    public function test_creator_cannot_reject_their_own_so_either(): void
    {
        [$service, $orders] = $this->makeService();
        $creator = $this->user(1, Role::Admin);
        $so = $this->seedSo($orders, SalesOrderStatus::PendingApproval, (int) $creator->id);

        $this->expectException(ValidationException::class);
        $service->reject($so->id, $creator);
    }
}
