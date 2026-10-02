<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderStatus;
use App\Entity\User;
use App\Repository\InMemory\InMemoryProductRepository;
use App\Repository\InMemory\InMemoryPurchaseOrderRepository;
use App\Repository\InMemory\InMemorySalesOrderRepository;
use App\Service\DashboardService;
use PHPUnit\Framework\TestCase;

final class DashboardServiceTest extends TestCase
{
    private function makeService(): array
    {
        $products = new InMemoryProductRepository();
        $productA = new Product(null, 'DASH-1', 'Product A', 1, 'pcs', 100, 150, 10);
        $productB = new Product(null, 'DASH-2', 'Product B', 1, 'pcs', 200, 300, 5);
        $products->create($productA);
        $products->create($productB);
        $products->seedStock($productA->id, 3); // below reorder point (10)
        $products->seedStock($productB->id, 20); // above reorder point (5)

        $purchaseOrders = new InMemoryPurchaseOrderRepository();
        $purchaseOrders->create(new PurchaseOrder(null, 1, 1, PurchaseOrderStatus::Ordered, '2026-09-01', 1));
        $purchaseOrders->create(new PurchaseOrder(null, 1, 1, PurchaseOrderStatus::Ordered, '2026-09-02', 1));
        $purchaseOrders->create(new PurchaseOrder(null, 1, 1, PurchaseOrderStatus::Received, '2026-08-01', 1));

        $salesOrders = new InMemorySalesOrderRepository();
        $salesOrders->create(new SalesOrder(null, 1, 1, SalesOrderStatus::PendingApproval, '2026-09-01', 2));
        $salesOrders->create(new SalesOrder(null, 1, 1, SalesOrderStatus::Approved, '2026-09-02', 3));
        $salesOrders->create(new SalesOrder(null, 1, 1, SalesOrderStatus::Approved, '2026-09-03', 2));

        return [new DashboardService($products, $purchaseOrders, $salesOrders), $productA, $productB];
    }

    public function test_admin_dashboard_reports_inventory_value_and_low_stock_count(): void
    {
        [$service] = $this->makeService();

        $stats = $service->forAdmin();

        // productA: 3 * 100 = 300, productB: 20 * 200 = 4000 -> total 4300
        $this->assertSame(4300.0, $stats['inventoryValue']);
        $this->assertSame(1, $stats['lowStockCount']);
        $this->assertSame(2, $stats['poByStatus'][PurchaseOrderStatus::Ordered->value]);
        $this->assertSame(1, $stats['poByStatus'][PurchaseOrderStatus::Received->value]);
        $this->assertSame(2, $stats['soByStatus'][SalesOrderStatus::Approved->value]);
    }

    public function test_sales_dashboard_is_scoped_to_the_viewers_own_orders(): void
    {
        [$service] = $this->makeService();
        $sales = new User(2, 'Sari Sales', 'sari', 'sari@test.local', 'hash', Role::Sales, true);

        $stats = $service->forSales($sales);

        // user 2 created SO1 (PendingApproval) and SO3 (Approved) - not SO2 (created by user 3)
        $this->assertSame(1, $stats['soByStatus'][SalesOrderStatus::PendingApproval->value]);
        $this->assertSame(1, $stats['soByStatus'][SalesOrderStatus::Approved->value]);
    }

    public function test_warehouse_dashboard_reports_pending_receipts_and_issues(): void
    {
        [$service] = $this->makeService();

        $stats = $service->forWarehouse();

        $this->assertSame(2, $stats['pendingReceipts']); // 2 Ordered POs
        $this->assertSame(2, $stats['pendingIssues']); // 2 Approved SOs
        $this->assertSame(1, $stats['lowStockCount']);
    }
}
