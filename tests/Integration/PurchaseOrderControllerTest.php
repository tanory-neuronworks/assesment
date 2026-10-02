<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Entity\PurchaseOrderStatus;
use App\Entity\Role;
use Tests\Support\OrderFixtures;

final class PurchaseOrderControllerTest extends ControllerTestCase
{
    use OrderFixtures;

    private const CSRF_MESSAGE = 'Sesi tidak valid, silakan coba lagi.';

    private function poLabel(int $id): string
    {
        return 'PO-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string,mixed>
     */
    private function validForm(int $supplierId, int $warehouseId, int $productId, string $qty = '4', string $cost = '1500'): array
    {
        return [
            'supplier_id' => (string) $supplierId,
            'warehouse_id' => (string) $warehouseId,
            'order_date' => date('Y-m-d'),
            'items' => [['product_id' => (string) $productId, 'qty_ordered' => $qty, 'cost_price' => $cost]],
        ];
    }

    // ---------------------------------------------------------------- index

    public function test_index_requires_login(): void
    {
        $this->assertRedirectTo('/login', $this->get('/purchase-orders'));
    }

    public function test_index_is_forbidden_for_sales(): void
    {
        $this->loginAs(Role::Sales);

        $this->assertSame(403, $this->get('/purchase-orders')->status);
        $this->expectException(ForbiddenException::class);
        $this->requestRaw('GET', '/purchase-orders');
    }

    public function test_index_renders_full_page_with_table_row_for_admin_and_warehouse_staff(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$this->makeProduct(), 3]]);

        foreach ([Role::Admin, Role::WarehouseStaff] as $role) {
            $this->logout();
            $this->loginAs($role);
            $result = $this->get('/purchase-orders', ['q' => $supplier->name, 'view' => 'table']);

            $this->assertSame(200, $result->status);
            $this->assertStringContainsString('<html', $result->body);
            $this->assertStringContainsString($this->poLabel((int) $po->id), $result->body);
            $this->assertStringContainsString($supplier->name, $result->body);
            $this->assertStringContainsString($warehouse->name, $result->body);
            $this->assertStringContainsString("/purchase-orders/{$po->id}", $result->body);
        }
    }

    public function test_index_defaults_to_board_tab_and_honours_view_table(): void
    {
        $this->loginAs(Role::Admin);

        $board = $this->get('/purchase-orders');
        $table = $this->get('/purchase-orders', ['view' => 'table']);

        $this->assertMatchesRegularExpression('/data-view-panel="table"\s+hidden/', $board->body);
        $this->assertDoesNotMatchRegularExpression('/data-view-panel="table"\s+hidden/', $table->body);
    }

    public function test_index_ajax_returns_table_fragment_only(): void
    {
        $supplier = $this->makeSupplier();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($supplier, $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 1]]);

        $result = $this->get('/purchase-orders', ['q' => $supplier->name], true);

        $this->assertSame(200, $result->status);
        $this->assertStringNotContainsString('<html', $result->body);
        $this->assertStringContainsString($this->poLabel((int) $po->id), $result->body);
    }

    public function test_index_search_without_match_shows_empty_message(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/purchase-orders', ['q' => 'zz_none_' . uniqid()], true);

        $this->assertStringContainsString('Belum ada Purchase Order.', $result->body);
    }

    public function test_index_filters_by_status_and_ignores_unknown_status(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $admin = $this->loginAs(Role::Admin);
        $product = $this->makeProduct();
        $ordered = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$product, 1]]);
        $cancelled = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$product, 1]], PurchaseOrderStatus::Cancelled);

        $onlyCancelled = $this->get('/purchase-orders', ['q' => $supplier->name, 'status' => 'Cancelled'], true);
        $bogus = $this->get('/purchase-orders', ['q' => $supplier->name, 'status' => 'Nope'], true);

        $this->assertStringContainsString($this->poLabel((int) $cancelled->id), $onlyCancelled->body);
        $this->assertStringNotContainsString($this->poLabel((int) $ordered->id), $onlyCancelled->body);
        $this->assertStringContainsString($this->poLabel((int) $ordered->id), $bogus->body);
        $this->assertStringContainsString($this->poLabel((int) $cancelled->id), $bogus->body);
    }

    public function test_index_paginates_and_keeps_filters_in_page_links(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $admin = $this->loginAs(Role::Admin);
        $product = $this->makeProduct();
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            $ids[] = (int) $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$product, 1]])->id;
        }

        $first = $this->get('/purchase-orders', ['q' => $supplier->name, 'per_page' => '10'], true);
        $second = $this->get('/purchase-orders', ['q' => $supplier->name, 'per_page' => '10', 'page' => '2'], true);

        $this->assertSame(10, substr_count($first->body, '</svg> Detail</a>'));
        $this->assertSame(1, substr_count($second->body, '</svg> Detail</a>'));
        $this->assertStringContainsString($this->poLabel(min($ids)), $second->body, 'Oldest PO is on page 2 (newest first).');
        $this->assertStringContainsString('page=2', $first->body);
    }

    public function test_index_board_ajax_filters_by_search_and_shows_empty_message(): void
    {
        $supplier = $this->makeSupplier();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($supplier, $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 2]]);

        $match = $this->get('/purchase-orders', ['ajax_view' => 'board', 'board_q' => $supplier->name, 'board_from' => '', 'board_to' => ''], true);
        $none = $this->get('/purchase-orders', ['ajax_view' => 'board', 'board_q' => 'zz_none_' . uniqid(), 'board_from' => '', 'board_to' => ''], true);

        $this->assertSame(200, $match->status);
        $this->assertStringNotContainsString('<html', $match->body);
        $this->assertStringContainsString($supplier->name, $match->body);
        $this->assertStringContainsString("/purchase-orders/{$po->id}", $match->body);
        $this->assertStringContainsString('Tidak ada yang cocok.', $none->body);
        $this->assertStringNotContainsString("/purchase-orders/{$po->id}", $none->body);
    }

    public function test_index_board_defaults_to_current_month_and_respects_explicit_date_range(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $admin = $this->loginAs(Role::Admin);
        $product = $this->makeProduct();
        $current = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$product, 1]], PurchaseOrderStatus::Ordered, date('Y-m-01'));
        $old = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$product, 1]], PurchaseOrderStatus::Ordered, '2001-01-15');

        $default = $this->get('/purchase-orders', ['ajax_view' => 'board', 'board_q' => $supplier->name], true);
        $ranged = $this->get('/purchase-orders', ['ajax_view' => 'board', 'board_q' => $supplier->name, 'board_from' => '2001-01-01', 'board_to' => '2001-01-31'], true);

        $this->assertStringContainsString("/purchase-orders/{$current->id}", $default->body);
        $this->assertStringNotContainsString("/purchase-orders/{$old->id}", $default->body);
        $this->assertStringContainsString("/purchase-orders/{$old->id}", $ranged->body);
        $this->assertStringNotContainsString("/purchase-orders/{$current->id}", $ranged->body);
    }

    public function test_index_full_page_renders_board_with_filters_applied(): void
    {
        $supplier = $this->makeSupplier();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($supplier, $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 2]]);

        $result = $this->get('/purchase-orders', ['board_q' => $supplier->name]);

        $this->assertStringContainsString('<html', $result->body);
        $this->assertStringContainsString("/purchase-orders/{$po->id}", $result->body);
        $this->assertStringContainsString('Total Purchase Order', $result->body);
    }

    // --------------------------------------------------------------- create

    public function test_create_page_requires_login_and_is_forbidden_for_sales(): void
    {
        $this->assertRedirectTo('/login', $this->get('/purchase-orders/create'));

        $this->loginAs(Role::Sales);
        $this->assertSame(403, $this->get('/purchase-orders/create')->status);
    }

    public function test_create_page_renders_shared_item_picker_with_purchase_hooks(): void
    {
        $active = $this->makeWarehouse();
        $inactive = $this->makeWarehouse(null, false);

        foreach ([Role::Admin, Role::WarehouseStaff] as $role) {
            $this->logout();
            $this->loginAs($role);
            $result = $this->get('/purchase-orders/create');
            $body = $result->body;

            $this->assertSame(200, $result->status);
            $this->assertStringContainsString('Buat Purchase Order', $body);
            $this->assertStringContainsString('action="/purchase-orders"', $body);
            $this->assertStringContainsString('name="_csrf"', $body);
            $this->assertStringContainsString('data-async-dropdown="suppliers"', $body);
            $this->assertStringContainsString('name="supplier_id"', $body);
            $this->assertStringContainsString('name="order_date"', $body);
            $this->assertStringContainsString($active->name, $body);
            $this->assertStringNotContainsString($inactive->name, $body, 'Inactive warehouses are not selectable.');
            // order-items.js hooks
            $this->assertMatchesRegularExpression('/<div class="line-item" data-item-picker data-qty-field="qty_ordered" data-price-field="cost_price">/', $body);
            $this->assertStringContainsString('data-item-picker-qty', $body);
            $this->assertStringContainsString('data-item-picker-price data-cost-price-input', $body);
            $this->assertStringContainsString('Harga Beli', $body);
            $this->assertStringContainsString('data-add-to-cart', $body);
            $this->assertStringContainsString('data-cart-items', $body);
            $this->assertStringContainsString('data-cart-card-template', $body);
            $this->assertStringContainsString('data-item-picker-error', $body);
            $this->assertStringContainsString('/assets/js/order-items.js', $body);
            $this->assertStringNotContainsString('data-qty-field="qty"', $body);
        }
    }

    public function test_create_page_shows_session_errors_and_old_input_after_failed_store(): void
    {
        $this->loginAs(Role::Admin);
        $date = '2031-02-03';

        $this->postWithCsrf('/purchase-orders', ['order_date' => $date]);
        $result = $this->get('/purchase-orders/create');

        $this->assertStringContainsString('Supplier wajib dipilih.', $result->body);
        $this->assertStringContainsString('Minimal 1 item produk wajib diisi.', $result->body);
        $this->assertStringContainsString('value="' . $date . '"', $result->body);
        $this->assertSame([], $this->sessionErrors(), 'Errors are consumed by the render.');
    }

    // ---------------------------------------------------------------- store

    public function test_store_creates_ordered_po_with_items_and_redirects_to_detail(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        foreach ([Role::Admin, Role::WarehouseStaff] as $role) {
            $this->logout();
            $user = $this->loginAs($role);
            $before = $this->countRows('purchase_orders');

            $result = $this->postWithCsrf('/purchase-orders', $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id, '4', '1500.50'));

            $id = (int) $this->pdo->query('SELECT MAX(id) FROM purchase_orders')->fetchColumn();
            $this->assertRedirectTo("/purchase-orders/{$id}", $result);
            $this->assertFlash('success', 'Purchase Order berhasil dibuat.');
            $this->assertSame($before + 1, $this->countRows('purchase_orders'));
            $row = $this->pdo->query("SELECT * FROM purchase_orders WHERE id = {$id}")->fetch();
            $this->assertSame('Ordered', $row['status']);
            $this->assertSame((int) $supplier->id, (int) $row['supplier_id']);
            $this->assertSame((int) $warehouse->id, (int) $row['warehouse_id']);
            $this->assertSame((int) $user->id, (int) $row['created_by']);
            $item = $this->pdo->query("SELECT * FROM purchase_order_items WHERE purchase_order_id = {$id}")->fetch();
            $this->assertSame((int) $product->id, (int) $item['product_id']);
            $this->assertSame(4, (int) $item['qty_ordered']);
            $this->assertSame(0, (int) $item['qty_received']);
            $this->assertSame(1500.5, (float) $item['cost_price']);
        }
    }

    public function test_store_skips_blank_item_rows_but_keeps_complete_ones(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->loginAs(Role::Admin);
        $form = $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id);
        $form['items'][] = ['product_id' => '', 'qty_ordered' => '', 'cost_price' => ''];

        $this->postWithCsrf('/purchase-orders', $form);

        $id = (int) $this->pdo->query('SELECT MAX(id) FROM purchase_orders')->fetchColumn();
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = {$id}")->fetchColumn());
    }

    public function test_store_with_empty_form_redirects_back_with_all_header_errors_and_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $before = $this->countRows('purchase_orders');

        $result = $this->postWithCsrf('/purchase-orders', ['order_date' => 'not-a-date', 'note' => 'keep me']);

        $this->assertRedirectTo('/purchase-orders/create', $result);
        $errors = $this->sessionErrors();
        $this->assertSame('Supplier wajib dipilih.', $errors['supplier_id'] ?? null);
        $this->assertSame('Gudang tujuan wajib dipilih.', $errors['warehouse_id'] ?? null);
        $this->assertSame('Tanggal order wajib diisi dengan format yang valid.', $errors['order_date'] ?? null);
        $this->assertSame('Minimal 1 item produk wajib diisi.', $errors['items'] ?? null);
        $this->assertSame('keep me', $this->sessionOldInput()['note'] ?? null);
        $this->assertSame($before, $this->countRows('purchase_orders'));
    }

    public function test_store_rejects_inactive_supplier_warehouse_and_product(): void
    {
        $supplier = $this->makeSupplier(null, false);
        $warehouse = $this->makeWarehouse(null, false);
        $product = $this->makeProduct(false);
        $this->loginAs(Role::Admin);
        $before = $this->countRows('purchase_orders');

        $result = $this->postWithCsrf('/purchase-orders', $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id));

        $this->assertRedirectTo('/purchase-orders/create', $result);
        $errors = $this->sessionErrors();
        $this->assertSame('Supplier tidak valid atau nonaktif.', $errors['supplier_id'] ?? null);
        $this->assertSame('Gudang tidak valid atau nonaktif.', $errors['warehouse_id'] ?? null);
        $this->assertSame('Produk tidak valid atau nonaktif.', $errors['items.0.product_id'] ?? null);
        $this->assertSame($before, $this->countRows('purchase_orders'));
    }

    public function test_store_reports_item_level_quantity_and_price_errors(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->loginAs(Role::Admin);
        $before = $this->countRows('purchase_orders');

        $badQty = $this->postWithCsrf('/purchase-orders', $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id, '0'));
        $errorsQty = $this->sessionErrors();
        $badPrice = $this->postWithCsrf('/purchase-orders', $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id, '2', '-5'));
        $errorsPrice = $this->sessionErrors();

        $this->assertRedirectTo('/purchase-orders/create', $badQty);
        $this->assertSame('Qty harus bilangan bulat > 0.', $errorsQty['items.0.qty_ordered'] ?? null);
        $this->assertSame('Minimal 1 item produk yang lengkap wajib diisi.', $errorsQty['items'] ?? null);
        $this->assertRedirectTo('/purchase-orders/create', $badPrice);
        $this->assertSame('Harga beli harus angka >= 0.', $errorsPrice['items.0.cost_price'] ?? null);
        $this->assertSame($before, $this->countRows('purchase_orders'));
    }

    public function test_store_treats_non_array_items_as_no_items(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf('/purchase-orders', [
            'supplier_id' => (string) $supplier->id,
            'warehouse_id' => (string) $warehouse->id,
            'order_date' => date('Y-m-d'),
            'items' => 'garbage',
        ]);

        $this->assertRedirectTo('/purchase-orders/create', $result);
        $this->assertSame('Minimal 1 item produk wajib diisi.', $this->sessionErrors()['items'] ?? null);
    }

    public function test_store_with_forged_csrf_redirects_with_error_and_creates_nothing(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->loginAs(Role::Admin);
        $this->csrfToken();
        $before = $this->countRows('purchase_orders');

        $result = $this->request('POST', '/purchase-orders', ['_csrf' => 'forged'] + $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id));

        $this->assertRedirectTo('/purchase-orders/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($before, $this->countRows('purchase_orders'));
    }

    public function test_store_with_missing_csrf_redirects_with_error_and_creates_nothing(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->loginAs(Role::Admin);
        $before = $this->countRows('purchase_orders');

        $result = $this->request('POST', '/purchase-orders', $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id));

        $this->assertRedirectTo('/purchase-orders/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($before, $this->countRows('purchase_orders'));
    }

    public function test_store_is_forbidden_for_sales_and_redirects_guest_to_login(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $form = $this->validForm((int) $supplier->id, (int) $warehouse->id, (int) $product->id);
        $before = $this->countRows('purchase_orders');

        $this->assertRedirectTo('/login', $this->request('POST', '/purchase-orders', $form));

        $this->loginAs(Role::Sales);
        $this->assertSame(403, $this->postWithCsrf('/purchase-orders', $form)->status);
        $this->assertSame($before, $this->countRows('purchase_orders'));
    }

    // ----------------------------------------------------------------- show

    public function test_show_renders_detail_with_receive_form_and_cancel_button(): void
    {
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$product, 7]]);

        foreach ([Role::Admin, Role::WarehouseStaff] as $role) {
            $this->logout();
            $this->loginAs($role);
            $result = $this->get("/purchase-orders/{$po->id}");

            $this->assertSame(200, $result->status);
            $this->assertStringContainsString($this->poLabel((int) $po->id), $result->body);
            $this->assertStringContainsString($supplier->name, $result->body);
            $this->assertStringContainsString($warehouse->name, $result->body);
            $this->assertStringContainsString($product->sku, $result->body);
            $this->assertStringContainsString('Dipesan', $result->body);
            $this->assertStringContainsString("action=\"/purchase-orders/{$po->id}/cancel\"", $result->body);
            $this->assertStringContainsString("action=\"/purchase-orders/{$po->id}/receive\"", $result->body);
            $this->assertStringContainsString('name="receive[' . $po->items[0]->id . ']"', $result->body);
            $this->assertStringContainsString('max="7"', $result->body);
        }
    }

    public function test_show_of_cancelled_po_has_no_receive_or_cancel_controls(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 2]], PurchaseOrderStatus::Cancelled);

        $result = $this->get("/purchase-orders/{$po->id}");

        $this->assertStringContainsString('Dibatalkan', $result->body);
        $this->assertStringNotContainsString("/purchase-orders/{$po->id}/cancel", $result->body);
        $this->assertStringNotContainsString('Catat Penerimaan', $result->body);
    }

    public function test_show_unknown_id_is_404_and_roles_are_enforced(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 2]]);

        $this->assertSame(404, $this->get('/purchase-orders/999999999')->status);

        $this->logout();
        $this->assertRedirectTo('/login', $this->get("/purchase-orders/{$po->id}"));

        $this->loginAs(Role::Sales);
        $this->assertSame(403, $this->get("/purchase-orders/{$po->id}")->status);
    }

    public function test_show_unknown_id_throws_not_found_through_raw_dispatch(): void
    {
        $this->loginAs(Role::Admin);

        $this->expectException(NotFoundException::class);
        $this->requestRaw('GET', '/purchase-orders/999999999');
    }

    // --------------------------------------------------------------- cancel

    public function test_cancel_marks_ordered_and_partially_received_po_cancelled(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $warehouse = $this->makeWarehouse();
        $supplier = $this->makeSupplier();
        $ordered = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$this->makeProduct(), 2]]);
        $partial = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$this->makeProduct(), 2]], PurchaseOrderStatus::PartiallyReceived);

        foreach ([$ordered, $partial] as $po) {
            $result = $this->postWithCsrf("/purchase-orders/{$po->id}/cancel");

            $this->assertRedirectTo("/purchase-orders/{$po->id}", $result);
            $this->assertFlash('success', 'Purchase Order dibatalkan.');
            $this->assertSame('Cancelled', $this->poStatus((int) $po->id));
        }
    }

    public function test_cancel_is_allowed_for_warehouse_staff(): void
    {
        $admin = $this->createUser(Role::Admin)['user'];
        $po = $this->seedPo($this->makeSupplier(), $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 2]]);
        $this->loginAs(Role::WarehouseStaff);

        $result = $this->postWithCsrf("/purchase-orders/{$po->id}/cancel");

        $this->assertRedirectTo("/purchase-orders/{$po->id}", $result);
        $this->assertSame('Cancelled', $this->poStatus((int) $po->id));
    }

    public function test_cancel_of_received_or_cancelled_po_flashes_error_and_keeps_status(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $supplier = $this->makeSupplier();
        $warehouse = $this->makeWarehouse();

        foreach ([PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled] as $status) {
            $po = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$this->makeProduct(), 2]], $status);

            $result = $this->postWithCsrf("/purchase-orders/{$po->id}/cancel");

            $this->assertRedirectTo("/purchase-orders/{$po->id}", $result);
            $this->assertFlash('error', 'PO hanya bisa dibatalkan selama belum diterima penuh.');
            $this->assertSame($status->value, $this->poStatus((int) $po->id));
        }
    }

    public function test_cancel_with_invalid_csrf_changes_nothing(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 2]]);
        $this->csrfToken();

        $forged = $this->request('POST', "/purchase-orders/{$po->id}/cancel", ['_csrf' => 'forged']);
        $this->assertRedirectTo("/purchase-orders/{$po->id}", $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);

        $missing = $this->request('POST', "/purchase-orders/{$po->id}/cancel");
        $this->assertRedirectTo("/purchase-orders/{$po->id}", $missing);
        $this->assertSame('Ordered', $this->poStatus((int) $po->id));
    }

    public function test_cancel_unknown_id_is_404_and_sales_is_forbidden(): void
    {
        $admin = $this->createUser(Role::Admin)['user'];
        $po = $this->seedPo($this->makeSupplier(), $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 2]]);

        $this->loginAs(Role::Admin);
        $this->assertSame(404, $this->postWithCsrf('/purchase-orders/999999999/cancel')->status);

        $this->logout();
        $this->assertRedirectTo('/login', $this->request('POST', "/purchase-orders/{$po->id}/cancel"));

        $this->loginAs(Role::Sales);
        $this->assertSame(403, $this->postWithCsrf("/purchase-orders/{$po->id}/cancel")->status);
        $this->assertSame('Ordered', $this->poStatus((int) $po->id));
    }

    // -------------------------------------------------------------- receive

    public function test_receive_partial_then_full_updates_status_stock_and_ledger(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->setStock((int) $product->id, (int) $warehouse->id, 3);
        $user = $this->loginAs(Role::WarehouseStaff);
        $po = $this->seedPo($this->makeSupplier(), $warehouse, (int) $user->id, [[$product, 10]]);
        $itemId = (int) $po->items[0]->id;

        $partial = $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [(string) $itemId => '4']]);

        $this->assertRedirectTo("/purchase-orders/{$po->id}", $partial);
        $this->assertFlash('success', 'Penerimaan barang berhasil dicatat.');
        $this->assertSame('PartiallyReceived', $this->poStatus((int) $po->id));
        $this->assertSame(7, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertSame(4, (int) $this->pdo->query("SELECT qty_received FROM purchase_order_items WHERE id = {$itemId}")->fetchColumn());
        $ledger = $this->ledgerFor('PurchaseOrder', (int) $po->id);
        $this->assertCount(1, $ledger);
        $this->assertSame('Receipt', $ledger[0]['movement_type']);
        $this->assertSame(4, (int) $ledger[0]['quantity']);
        $this->assertSame((int) $product->id, (int) $ledger[0]['product_id']);
        $this->assertSame((int) $warehouse->id, (int) $ledger[0]['warehouse_id']);
        $this->assertSame((int) $user->id, (int) $ledger[0]['performed_by']);

        $page = $this->get("/purchase-orders/{$po->id}");
        $this->assertStringContainsString('Diterima Sebagian', $page->body);
        $this->assertStringContainsString('max="6"', $page->body);

        $full = $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [(string) $itemId => '6']]);

        $this->assertRedirectTo("/purchase-orders/{$po->id}", $full);
        $this->assertSame('Received', $this->poStatus((int) $po->id));
        $this->assertSame(13, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertCount(2, $this->ledgerFor('PurchaseOrder', (int) $po->id));
        $this->assertStringContainsString('Diterima Penuh', $this->get("/purchase-orders/{$po->id}")->body);
    }

    public function test_receive_full_in_one_go_across_multiple_items_creates_stock_for_new_warehouse(): void
    {
        $warehouse = $this->makeWarehouse();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $warehouse, (int) $admin->id, [[$a, 2], [$b, 5]]);

        $result = $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [
            (string) $po->items[0]->id => '2',
            (string) $po->items[1]->id => '5',
        ]]);

        $this->assertRedirectTo("/purchase-orders/{$po->id}", $result);
        $this->assertSame('Received', $this->poStatus((int) $po->id));
        $this->assertSame(2, $this->stockOf((int) $a->id, (int) $warehouse->id));
        $this->assertSame(5, $this->stockOf((int) $b->id, (int) $warehouse->id));
        $this->assertCount(2, $this->ledgerFor('PurchaseOrder', (int) $po->id));
    }

    public function test_receive_over_receipt_is_rejected_with_error_and_changes_nothing(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $warehouse, (int) $admin->id, [[$product, 5]]);
        $itemId = (int) $po->items[0]->id;

        $result = $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [(string) $itemId => '6']]);

        $this->assertRedirectTo("/purchase-orders/{$po->id}", $result);
        $this->assertStringContainsString('Qty melebihi sisa yang belum diterima (sisa: 5).', $this->flashes()['error'] ?? '');
        $this->assertArrayNotHasKey('success', $this->flashes());
        $this->assertSame('Ordered', $this->poStatus((int) $po->id));
        $this->assertSame(0, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertSame([], $this->ledgerFor('PurchaseOrder', (int) $po->id));
    }

    public function test_receive_over_receipt_after_partial_receipt_uses_remaining_quantity(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $warehouse, (int) $admin->id, [[$product, 5]]);
        $itemId = (string) $po->items[0]->id;
        $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [$itemId => '3']]);

        $result = $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [$itemId => '3']]);

        $this->assertRedirectTo("/purchase-orders/{$po->id}", $result);
        $this->assertStringContainsString('sisa: 2', $this->flashes()['error'] ?? '');
        $this->assertSame(3, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertSame('PartiallyReceived', $this->poStatus((int) $po->id));
    }

    public function test_receive_with_no_quantity_or_unknown_item_is_rejected(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $warehouse, (int) $admin->id, [[$product, 5]]);

        $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [(string) $po->items[0]->id => '0']]);
        $this->assertStringContainsString('Isi minimal satu qty penerimaan.', $this->flashes()['error'] ?? '');

        $this->postWithCsrf("/purchase-orders/{$po->id}/receive");
        $this->assertStringContainsString('Isi minimal satu qty penerimaan.', $this->flashes()['error'] ?? '');

        $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => ['999999999' => '1']]);
        $this->assertStringContainsString('Item tidak ditemukan pada PO ini.', $this->flashes()['error'] ?? '');

        $this->assertSame('Ordered', $this->poStatus((int) $po->id));
        $this->assertSame(0, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertSame([], $this->ledgerFor('PurchaseOrder', (int) $po->id));
    }

    public function test_receive_on_cancelled_or_received_po_is_rejected(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $admin = $this->loginAs(Role::Admin);
        $supplier = $this->makeSupplier();

        foreach ([PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Received] as $status) {
            $po = $this->seedPo($supplier, $warehouse, (int) $admin->id, [[$product, 5]], $status);

            $result = $this->postWithCsrf("/purchase-orders/{$po->id}/receive", ['receive' => [(string) $po->items[0]->id => '1']]);

            $this->assertRedirectTo("/purchase-orders/{$po->id}", $result);
            $this->assertFlash('error', 'PO tidak dalam status yang bisa menerima barang.');
            $this->assertSame($status->value, $this->poStatus((int) $po->id));
        }
        $this->assertSame(0, $this->stockOf((int) $product->id, (int) $warehouse->id));
    }

    public function test_receive_with_invalid_csrf_changes_nothing(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $admin = $this->loginAs(Role::Admin);
        $po = $this->seedPo($this->makeSupplier(), $warehouse, (int) $admin->id, [[$product, 5]]);
        $lines = ['receive' => [(string) $po->items[0]->id => '2']];
        $this->csrfToken();

        $forged = $this->request('POST', "/purchase-orders/{$po->id}/receive", ['_csrf' => 'forged'] + $lines);
        $this->assertRedirectTo("/purchase-orders/{$po->id}", $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);

        $missing = $this->request('POST', "/purchase-orders/{$po->id}/receive", $lines);
        $this->assertRedirectTo("/purchase-orders/{$po->id}", $missing);
        $this->assertFlash('error', self::CSRF_MESSAGE);

        $this->assertSame('Ordered', $this->poStatus((int) $po->id));
        $this->assertSame(0, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertSame([], $this->ledgerFor('PurchaseOrder', (int) $po->id));
    }

    public function test_receive_unknown_id_is_404_and_sales_is_forbidden(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $admin = $this->createUser(Role::Admin)['user'];
        $po = $this->seedPo($this->makeSupplier(), $warehouse, (int) $admin->id, [[$product, 5]]);
        $lines = ['receive' => [(string) $po->items[0]->id => '2']];

        $this->loginAs(Role::Admin);
        $this->assertSame(404, $this->postWithCsrf('/purchase-orders/999999999/receive', $lines)->status);

        $this->logout();
        $this->assertRedirectTo('/login', $this->request('POST', "/purchase-orders/{$po->id}/receive", $lines));

        $this->loginAs(Role::Sales);
        $this->assertSame(403, $this->postWithCsrf("/purchase-orders/{$po->id}/receive", $lines)->status);
        $this->assertSame(0, $this->stockOf((int) $product->id, (int) $warehouse->id));
    }
}
