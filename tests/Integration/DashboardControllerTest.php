<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Role;

final class DashboardControllerTest extends ControllerTestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->assertRedirectTo('/login', $this->get('/'));
    }

    public function test_admin_sees_inventory_and_both_order_breakdowns(): void
    {
        $user = $this->loginAs(Role::Admin);

        $result = $this->get('/');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Halo, ' . $user->name, $result->body);
        $this->assertStringContainsString('Nilai Inventori', $result->body);
        $this->assertStringContainsString('Produk di Bawah Reorder Point', $result->body);
        $this->assertStringContainsString('Purchase Order per Status', $result->body);
        $this->assertStringContainsString('Sales Order per Status', $result->body);
        $this->assertStringContainsString('/reports/stock-ledger', $result->body);
    }

    public function test_sales_sees_only_their_own_order_summary(): void
    {
        $user = $this->loginAs(Role::Sales);

        $result = $this->get('/');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Halo, ' . $user->name, $result->body);
        $this->assertStringContainsString('Order Saya per Status', $result->body);
        $this->assertStringContainsString('Ekspor Order Saya', $result->body);
        $this->assertStringNotContainsString('Nilai Inventori', $result->body);
        $this->assertStringNotContainsString('Purchase Order per Status', $result->body);
    }

    public function test_warehouse_staff_sees_pending_receipts_and_issues(): void
    {
        $user = $this->loginAs(Role::WarehouseStaff);

        $result = $this->get('/');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Halo, ' . $user->name, $result->body);
        $this->assertStringContainsString('PO Menunggu Penerimaan', $result->body);
        $this->assertStringContainsString('SO Menunggu Goods Issue', $result->body);
        $this->assertStringContainsString('Produk Low Stock', $result->body);
        $this->assertStringNotContainsString('Nilai Inventori', $result->body);
        $this->assertStringNotContainsString('Order Saya per Status', $result->body);
    }

    public function test_deactivated_user_session_is_dropped_and_redirected_to_login(): void
    {
        $user = $this->loginAs(Role::Admin);
        $this->userService->setActive((int) $user->id, false);

        $this->assertRedirectTo('/login', $this->get('/'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }
}
