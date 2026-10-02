<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Customer;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderItem;
use App\Entity\SalesOrderStatus;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Repository\Mysql\MysqlCustomerRepository;
use App\Repository\Mysql\MysqlSalesOrderRepository;
use App\Repository\Mysql\MysqlUserRepository;
use App\Repository\Mysql\MysqlWarehouseRepository;

final class SalesOrderPaginationIntegrationTest extends IntegrationTestCase
{
    private function seedOrders(): array
    {
        $customers = new MysqlCustomerRepository($this->pdo);
        $warehouses = new MysqlWarehouseRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $orders = new MysqlSalesOrderRepository($this->pdo);

        $marker = uniqid();
        $customerId = $customers->create(new Customer(null, "ITEST Pag Customer {$marker}", '', ''));
        $warehouseId = $warehouses->create(new Warehouse(null, 'ITEST Pag Warehouse ' . uniqid(), 'Nowhere'));
        $userA = $users->create(new User(null, 'ITEST Pag Sales A', 'itest_pag_a_' . uniqid(), 'itest_pag_a_' . uniqid() . '@example.test', password_hash('x', PASSWORD_DEFAULT), Role::Sales, true));
        $userB = $users->create(new User(null, 'ITEST Pag Sales B', 'itest_pag_b_' . uniqid(), 'itest_pag_b_' . uniqid() . '@example.test', password_hash('x', PASSWORD_DEFAULT), Role::Sales, true));

        $statuses = [
            SalesOrderStatus::Draft, SalesOrderStatus::Draft,
            SalesOrderStatus::PendingApproval,
            SalesOrderStatus::Approved, SalesOrderStatus::Approved,
        ];
        foreach ($statuses as $i => $status) {
            $createdBy = $i % 2 === 0 ? $userA : $userB;
            $orders->create(new SalesOrder(
                id: null,
                customerId: $customerId,
                warehouseId: $warehouseId,
                status: $status,
                orderDate: '2026-09-0' . ($i + 1),
                createdBy: $createdBy,
                items: [new SalesOrderItem(null, 0, 1, 1, 100)],
            ));
        }

        return ['orders' => $orders, 'userA' => $userA, 'userB' => $userB, 'marker' => $marker];
    }

    public function test_status_filter_narrows_results(): void
    {
        $f = $this->seedOrders();

        // Scope by the test's own customer-name marker so pre-existing seed
        // data (schema-and-seed.sql also ships Approved SOs) can't leak in.
        $result = $f['orders']->paginate($f['marker'], SalesOrderStatus::Approved, null, 1, 20);

        $this->assertSame(2, $result->total);
    }

    public function test_created_by_scoping_combined_with_status_filter(): void
    {
        $f = $this->seedOrders();

        // userA created indices 0,2,4 -> Draft, PendingApproval, Approved
        $result = $f['orders']->paginate(null, SalesOrderStatus::Approved, $f['userA'], 1, 20);

        $this->assertSame(1, $result->total);
        $this->assertSame($f['userA'], $result->items[0]->createdBy);
    }

    public function test_created_by_scoping_alone_matches_only_that_users_orders(): void
    {
        $f = $this->seedOrders();

        $result = $f['orders']->paginate(null, null, $f['userB'], 1, 20);

        $this->assertSame(2, $result->total); // userB created indices 1,3
    }
}
