<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ForbiddenException;
use App\Entity\Role;
use App\Entity\SalesOrderStatus;
use Tests\Support\OrderFixtures;

final class SalesOrderControllerTest extends ControllerTestCase
{
    use OrderFixtures;

    private const CSRF_MESSAGE = 'Sesi tidak valid, silakan coba lagi.';

    private function soLabel(int $id): string
    {
        return 'SO-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string,mixed>
     */
    private function validForm(int $customerId, int $warehouseId, int $productId, string $qty = '3', string $price = '2750'): array
    {
        return [
            'customer_id' => (string) $customerId,
            'warehouse_id' => (string) $warehouseId,
            'order_date' => date('Y-m-d'),
            'items' => [['product_id' => (string) $productId, 'qty' => $qty, 'sell_price' => $price]],
        ];
    }

    // ---------------------------------------------------------------- index

    public function test_index_requires_login(): void
    {
        $this->assertRedirectTo('/login', $this->get('/sales-orders'));
    }

    public function test_index_renders_table_row_for_admin_sales_owner_and_warehouse_staff(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $sales = $this->loginAs(Role::Sales);
        $so = $this->seedSo($customer, $warehouse, (int) $sales->id, [[$this->makeProduct(), 3]]);

        foreach ([Role::Sales, Role::Admin, Role::WarehouseStaff] as $role) {
            $this->logout();
            if ($role === Role::Sales) {
                $this->auth->login($sales);
            } else {
                $this->loginAs($role);
            }
            $result = $this->get('/sales-orders', ['q' => $customer->name, 'view' => 'table']);

            $this->assertSame(200, $result->status);
            $this->assertStringContainsString('<html', $result->body);
            $this->assertStringContainsString($this->soLabel((int) $so->id), $result->body);
            $this->assertStringContainsString($customer->name, $result->body);
            $this->assertStringContainsString("/sales-orders/{$so->id}", $result->body);
        }
    }

    public function test_index_sales_sees_only_own_orders_while_admin_sees_all(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $me = $this->loginAs(Role::Sales);
        $other = $this->createUser(Role::Sales)['user'];
        $mine = $this->seedSo($customer, $warehouse, (int) $me->id, [[$product, 1]]);
        $theirs = $this->seedSo($customer, $warehouse, (int) $other->id, [[$product, 1]]);

        $asSales = $this->get('/sales-orders', ['q' => $customer->name], true);
        $this->assertStringContainsString($this->soLabel((int) $mine->id), $asSales->body);
        $this->assertStringNotContainsString($this->soLabel((int) $theirs->id), $asSales->body);

        $salesBoard = $this->get('/sales-orders', ['ajax_view' => 'board', 'board_q' => $customer->name, 'board_from' => '', 'board_to' => ''], true);
        $this->assertStringContainsString("/sales-orders/{$mine->id}", $salesBoard->body);
        $this->assertStringNotContainsString("/sales-orders/{$theirs->id}", $salesBoard->body);

        $this->logout();
        $this->loginAs(Role::Admin);
        $asAdmin = $this->get('/sales-orders', ['q' => $customer->name], true);
        $this->assertStringContainsString($this->soLabel((int) $mine->id), $asAdmin->body);
        $this->assertStringContainsString($this->soLabel((int) $theirs->id), $asAdmin->body);
    }

    public function test_index_defaults_to_board_tab_and_honours_view_table(): void
    {
        $this->loginAs(Role::Admin);

        $board = $this->get('/sales-orders');
        $table = $this->get('/sales-orders', ['view' => 'table']);

        $this->assertMatchesRegularExpression('/data-view-panel="table"\s+hidden/', $board->body);
        $this->assertDoesNotMatchRegularExpression('/data-view-panel="table"\s+hidden/', $table->body);
    }

    public function test_index_ajax_returns_table_fragment_and_empty_message(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->loginAs(Role::Admin);
        $so = $this->seedSo($customer, $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 1]]);

        $hit = $this->get('/sales-orders', ['q' => $customer->name], true);
        $miss = $this->get('/sales-orders', ['q' => 'zz_none_' . uniqid()], true);

        $this->assertStringNotContainsString('<html', $hit->body);
        $this->assertStringContainsString($this->soLabel((int) $so->id), $hit->body);
        $this->assertStringContainsString('Belum ada Sales Order.', $miss->body);
    }

    public function test_index_filters_by_status_and_ignores_unknown_status(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $admin = $this->loginAs(Role::Admin);
        $product = $this->makeProduct();
        $draft = $this->seedSo($customer, $warehouse, (int) $admin->id, [[$product, 1]]);
        $approved = $this->seedSo($customer, $warehouse, (int) $admin->id, [[$product, 1]], SalesOrderStatus::Approved);

        $only = $this->get('/sales-orders', ['q' => $customer->name, 'status' => 'Approved'], true);
        $bogus = $this->get('/sales-orders', ['q' => $customer->name, 'status' => 'Nope'], true);

        $this->assertStringContainsString($this->soLabel((int) $approved->id), $only->body);
        $this->assertStringNotContainsString($this->soLabel((int) $draft->id), $only->body);
        $this->assertStringContainsString($this->soLabel((int) $draft->id), $bogus->body);
        $this->assertStringContainsString($this->soLabel((int) $approved->id), $bogus->body);
    }

    public function test_index_paginates_with_per_page_and_page_params(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $admin = $this->loginAs(Role::Admin);
        $product = $this->makeProduct();
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            $ids[] = (int) $this->seedSo($customer, $warehouse, (int) $admin->id, [[$product, 1]])->id;
        }

        $first = $this->get('/sales-orders', ['q' => $customer->name, 'per_page' => '10'], true);
        $second = $this->get('/sales-orders', ['q' => $customer->name, 'per_page' => '10', 'page' => '2'], true);

        $this->assertSame(10, substr_count($first->body, '</svg> Detail</a>'));
        $this->assertSame(1, substr_count($second->body, '</svg> Detail</a>'));
        $this->assertStringContainsString($this->soLabel(min($ids)), $second->body);
        $this->assertStringContainsString('page=2', $first->body);
    }

    public function test_index_board_ajax_filters_by_search_dates_and_shows_empty_message(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $admin = $this->loginAs(Role::Admin);
        $product = $this->makeProduct();
        $current = $this->seedSo($customer, $warehouse, (int) $admin->id, [[$product, 1]], SalesOrderStatus::Draft, date('Y-m-01'));
        $old = $this->seedSo($customer, $warehouse, (int) $admin->id, [[$product, 1]], SalesOrderStatus::Draft, '2001-01-15');

        $default = $this->get('/sales-orders', ['ajax_view' => 'board', 'board_q' => $customer->name], true);
        $ranged = $this->get('/sales-orders', ['ajax_view' => 'board', 'board_q' => $customer->name, 'board_from' => '2001-01-01', 'board_to' => '2001-01-31'], true);
        $none = $this->get('/sales-orders', ['ajax_view' => 'board', 'board_q' => 'zz_none_' . uniqid(), 'board_from' => '', 'board_to' => ''], true);

        $this->assertStringNotContainsString('<html', $default->body);
        $this->assertStringContainsString("/sales-orders/{$current->id}", $default->body);
        $this->assertStringNotContainsString("/sales-orders/{$old->id}", $default->body);
        $this->assertStringContainsString("/sales-orders/{$old->id}", $ranged->body);
        $this->assertStringNotContainsString("/sales-orders/{$current->id}", $ranged->body);
        $this->assertStringContainsString('Tidak ada yang cocok.', $none->body);
    }

    public function test_index_full_page_renders_board(): void
    {
        $customer = $this->makeCustomer();
        $admin = $this->loginAs(Role::Admin);
        $so = $this->seedSo($customer, $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 1]]);

        $result = $this->get('/sales-orders', ['board_q' => $customer->name]);

        $this->assertStringContainsString('<html', $result->body);
        $this->assertStringContainsString("/sales-orders/{$so->id}", $result->body);
        $this->assertStringContainsString('Total Sales Order', $result->body);
    }

    // --------------------------------------------------------------- create

    public function test_create_page_requires_login_and_is_forbidden_for_warehouse_staff(): void
    {
        $this->assertRedirectTo('/login', $this->get('/sales-orders/create'));

        $this->loginAs(Role::WarehouseStaff);
        $this->assertSame(403, $this->get('/sales-orders/create')->status);
        $this->expectException(ForbiddenException::class);
        $this->requestRaw('GET', '/sales-orders/create');
    }

    public function test_create_page_renders_shared_item_picker_with_sales_hooks(): void
    {
        $active = $this->makeWarehouse();
        $inactive = $this->makeWarehouse(null, false);

        foreach ([Role::Admin, Role::Sales] as $role) {
            $this->logout();
            $this->loginAs($role);
            $result = $this->get('/sales-orders/create');
            $body = $result->body;

            $this->assertSame(200, $result->status);
            $this->assertStringContainsString('Buat Sales Order', $body);
            $this->assertStringContainsString('action="/sales-orders"', $body);
            $this->assertStringContainsString('name="_csrf"', $body);
            $this->assertStringContainsString('data-async-dropdown="customers"', $body);
            $this->assertStringContainsString('name="customer_id"', $body);
            $this->assertStringContainsString($active->name, $body);
            $this->assertStringNotContainsString($inactive->name, $body);
            $this->assertMatchesRegularExpression('/<div class="line-item" data-item-picker data-qty-field="qty" data-price-field="sell_price">/', $body);
            $this->assertStringContainsString('data-item-picker-qty', $body);
            $this->assertStringContainsString('data-item-picker-price data-sell-price-input', $body);
            $this->assertStringContainsString('Harga Jual', $body);
            $this->assertStringContainsString('data-add-to-cart', $body);
            $this->assertStringContainsString('data-cart-items', $body);
            $this->assertStringContainsString('data-cart-card-template', $body);
            $this->assertStringContainsString('data-item-picker-error', $body);
            $this->assertStringContainsString('/assets/js/order-items.js', $body);
            $this->assertStringContainsString('Simpan sebagai Draft', $body);
            $this->assertStringNotContainsString('data-qty-field="qty_ordered"', $body);
        }
    }

    public function test_create_page_shows_session_errors_after_failed_store(): void
    {
        $this->loginAs(Role::Sales);

        $this->postWithCsrf('/sales-orders', ['order_date' => '2031-02-03']);
        $result = $this->get('/sales-orders/create');

        $this->assertStringContainsString('Customer wajib dipilih.', $result->body);
        $this->assertStringContainsString('Minimal 1 item produk wajib diisi.', $result->body);
        $this->assertStringContainsString('value="2031-02-03"', $result->body);
    }

    // ---------------------------------------------------------------- store

    public function test_store_creates_draft_so_owned_by_creator_and_redirects_to_detail(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();

        foreach ([Role::Sales, Role::Admin] as $role) {
            $this->logout();
            $user = $this->loginAs($role);
            $before = $this->countRows('sales_orders');

            $result = $this->postWithCsrf('/sales-orders', $this->validForm((int) $customer->id, (int) $warehouse->id, (int) $product->id, '3', '2750.25'));

            $id = (int) $this->pdo->query('SELECT MAX(id) FROM sales_orders')->fetchColumn();
            $this->assertRedirectTo("/sales-orders/{$id}", $result);
            $this->assertFlash('success', 'Sales Order berhasil dibuat sebagai Draft.');
            $this->assertSame($before + 1, $this->countRows('sales_orders'));
            $row = $this->pdo->query("SELECT * FROM sales_orders WHERE id = {$id}")->fetch();
            $this->assertSame('Draft', $row['status']);
            $this->assertSame((int) $user->id, (int) $row['created_by']);
            $this->assertNull($row['approved_by']);
            $this->assertSame((int) $customer->id, (int) $row['customer_id']);
            $this->assertSame((int) $warehouse->id, (int) $row['warehouse_id']);
            $item = $this->pdo->query("SELECT * FROM sales_order_items WHERE sales_order_id = {$id}")->fetch();
            $this->assertSame((int) $product->id, (int) $item['product_id']);
            $this->assertSame(3, (int) $item['qty']);
            $this->assertSame(2750.25, (float) $item['sell_price']);
        }
    }

    public function test_store_with_empty_form_redirects_back_with_errors_and_creates_nothing(): void
    {
        $this->loginAs(Role::Sales);
        $before = $this->countRows('sales_orders');

        $result = $this->postWithCsrf('/sales-orders', ['order_date' => '', 'note' => 'keep me']);

        $this->assertRedirectTo('/sales-orders/create', $result);
        $errors = $this->sessionErrors();
        $this->assertSame('Customer wajib dipilih.', $errors['customer_id'] ?? null);
        $this->assertSame('Gudang asal wajib dipilih.', $errors['warehouse_id'] ?? null);
        $this->assertSame('Tanggal order wajib diisi dengan format yang valid.', $errors['order_date'] ?? null);
        $this->assertSame('Minimal 1 item produk wajib diisi.', $errors['items'] ?? null);
        $this->assertSame('keep me', $this->sessionOldInput()['note'] ?? null);
        $this->assertSame($before, $this->countRows('sales_orders'));
    }

    public function test_store_rejects_inactive_references_and_bad_item_values(): void
    {
        $customer = $this->makeCustomer(null, false);
        $warehouse = $this->makeWarehouse(null, false);
        $product = $this->makeProduct(false);
        $good = $this->makeProduct();
        $this->loginAs(Role::Sales);
        $before = $this->countRows('sales_orders');

        $inactive = $this->postWithCsrf('/sales-orders', $this->validForm((int) $customer->id, (int) $warehouse->id, (int) $product->id));
        $inactiveErrors = $this->sessionErrors();
        $badQty = $this->postWithCsrf('/sales-orders', $this->validForm((int) $customer->id, (int) $warehouse->id, (int) $good->id, '1.5'));
        $qtyErrors = $this->sessionErrors();
        $badPrice = $this->postWithCsrf('/sales-orders', $this->validForm((int) $customer->id, (int) $warehouse->id, (int) $good->id, '1', 'abc'));
        $priceErrors = $this->sessionErrors();

        $this->assertRedirectTo('/sales-orders/create', $inactive);
        $this->assertSame('Customer tidak valid atau nonaktif.', $inactiveErrors['customer_id'] ?? null);
        $this->assertSame('Gudang tidak valid atau nonaktif.', $inactiveErrors['warehouse_id'] ?? null);
        $this->assertSame('Produk tidak valid atau nonaktif.', $inactiveErrors['items.0.product_id'] ?? null);
        $this->assertRedirectTo('/sales-orders/create', $badQty);
        $this->assertSame('Qty harus bilangan bulat > 0.', $qtyErrors['items.0.qty'] ?? null);
        $this->assertRedirectTo('/sales-orders/create', $badPrice);
        $this->assertSame('Harga jual harus angka >= 0.', $priceErrors['items.0.sell_price'] ?? null);
        $this->assertSame($before, $this->countRows('sales_orders'));
    }

    public function test_store_with_forged_or_missing_csrf_creates_nothing(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->loginAs(Role::Sales);
        $this->csrfToken();
        $form = $this->validForm((int) $customer->id, (int) $warehouse->id, (int) $product->id);
        $before = $this->countRows('sales_orders');

        $forged = $this->request('POST', '/sales-orders', ['_csrf' => 'forged'] + $form);
        $this->assertRedirectTo('/sales-orders/create', $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);

        unset($_SESSION['_flash']);
        $missing = $this->request('POST', '/sales-orders', $form);
        $this->assertRedirectTo('/sales-orders/create', $missing);
        $this->assertFlash('error', self::CSRF_MESSAGE);

        $this->assertSame($before, $this->countRows('sales_orders'));
    }

    public function test_store_is_forbidden_for_warehouse_staff_and_redirects_guest(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $form = $this->validForm((int) $customer->id, (int) $warehouse->id, (int) $product->id);
        $before = $this->countRows('sales_orders');

        $this->assertRedirectTo('/login', $this->request('POST', '/sales-orders', $form));

        $this->loginAs(Role::WarehouseStaff);
        $this->assertSame(403, $this->postWithCsrf('/sales-orders', $form)->status);
        $this->assertSame($before, $this->countRows('sales_orders'));
    }

    // ----------------------------------------------------------------- show

    public function test_show_renders_detail_for_owner_admin_and_warehouse_staff(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $sales = $this->loginAs(Role::Sales);
        $so = $this->seedSo($customer, $warehouse, (int) $sales->id, [[$product, 4]]);

        foreach ([Role::Sales, Role::Admin, Role::WarehouseStaff] as $role) {
            $this->logout();
            if ($role === Role::Sales) {
                $this->auth->login($sales);
            } else {
                $this->loginAs($role);
            }
            $result = $this->get("/sales-orders/{$so->id}");

            $this->assertSame(200, $result->status);
            $this->assertStringContainsString($this->soLabel((int) $so->id), $result->body);
            $this->assertStringContainsString($customer->name, $result->body);
            $this->assertStringContainsString($warehouse->name, $result->body);
            $this->assertStringContainsString($product->sku, $result->body);
            $this->assertStringContainsString('Draft', $result->body);
        }
    }

    public function test_show_hides_other_sales_users_order_with_403(): void
    {
        $owner = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $owner->id, [[$this->makeProduct(), 1]]);
        $this->loginAs(Role::Sales);

        $this->assertSame(403, $this->get("/sales-orders/{$so->id}")->status);
        $this->expectException(ForbiddenException::class);
        $this->requestRaw('GET', "/sales-orders/{$so->id}");
    }

    public function test_show_unknown_id_is_404_and_guest_is_redirected(): void
    {
        $this->assertRedirectTo('/login', $this->get('/sales-orders/1'));

        $this->loginAs(Role::Sales);
        $this->assertSame(404, $this->get('/sales-orders/999999999')->status);
    }

    public function test_show_action_buttons_follow_status_and_role(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $sales = $this->createUser(Role::Sales)['user'];
        $draft = $this->seedSo($customer, $warehouse, (int) $sales->id, [[$product, 1]]);
        $pending = $this->seedSo($customer, $warehouse, (int) $sales->id, [[$product, 1]], SalesOrderStatus::PendingApproval);
        $approved = $this->seedSo($customer, $warehouse, (int) $sales->id, [[$product, 1]], SalesOrderStatus::Approved);

        $this->loginAs(Role::Admin);
        $draftPage = $this->get("/sales-orders/{$draft->id}")->body;
        $pendingPage = $this->get("/sales-orders/{$pending->id}")->body;
        $approvedPage = $this->get("/sales-orders/{$approved->id}")->body;

        $this->assertStringContainsString("/sales-orders/{$draft->id}/submit", $draftPage);
        $this->assertStringNotContainsString("/sales-orders/{$draft->id}/approve", $draftPage);
        $this->assertStringContainsString("/sales-orders/{$pending->id}/approve", $pendingPage);
        $this->assertStringContainsString("/sales-orders/{$pending->id}/reject", $pendingPage);
        $this->assertStringContainsString("/sales-orders/{$approved->id}/issue", $approvedPage);

        $this->logout();
        $this->loginAs(Role::WarehouseStaff);
        $warehousePage = $this->get("/sales-orders/{$approved->id}")->body;
        $this->assertStringContainsString("/sales-orders/{$approved->id}/issue", $warehousePage);
        $this->assertStringNotContainsString("/sales-orders/{$approved->id}/cancel", $warehousePage);
    }

    public function test_show_tells_admin_creator_they_cannot_approve_their_own_pending_order(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 1]], SalesOrderStatus::PendingApproval);

        $body = $this->get("/sales-orders/{$so->id}")->body;

        $this->assertStringContainsString('tidak bisa menyetujui/menolak order milik sendiri', $body);
        $this->assertStringNotContainsString("/sales-orders/{$so->id}/approve", $body);
    }

    // --------------------------------------------------------------- submit

    public function test_submit_moves_own_draft_to_pending_approval(): void
    {
        $sales = $this->loginAs(Role::Sales);
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]]);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/submit");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('success', 'Sales Order diajukan untuk persetujuan.');
        $this->assertSame('PendingApproval', $this->soStatus((int) $so->id));
    }

    public function test_submit_by_admin_for_someone_elses_draft_is_allowed(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]]);
        $this->loginAs(Role::Admin);

        $this->postWithCsrf("/sales-orders/{$so->id}/submit");

        $this->assertSame('PendingApproval', $this->soStatus((int) $so->id));
    }

    public function test_submit_by_another_sales_user_is_rejected(): void
    {
        $owner = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $owner->id, [[$this->makeProduct(), 1]]);
        $this->loginAs(Role::Sales);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/submit");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('error', 'Anda tidak berwenang mengajukan SO ini.');
        $this->assertSame('Draft', $this->soStatus((int) $so->id));
    }

    public function test_submit_from_non_draft_status_is_rejected(): void
    {
        $sales = $this->loginAs(Role::Sales);
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]], SalesOrderStatus::Approved);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/submit");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('error', 'SO hanya bisa diajukan dari status Draft.');
        $this->assertSame('Approved', $this->soStatus((int) $so->id));
    }

    public function test_submit_csrf_roles_and_unknown_id(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]]);
        $this->auth->login($sales);
        $this->csrfToken();

        $forged = $this->request('POST', "/sales-orders/{$so->id}/submit", ['_csrf' => 'forged']);
        $this->assertRedirectTo("/sales-orders/{$so->id}", $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame('Draft', $this->soStatus((int) $so->id));

        $this->assertSame(404, $this->postWithCsrf('/sales-orders/999999999/submit')->status);

        $this->logout();
        $this->assertRedirectTo('/login', $this->request('POST', "/sales-orders/{$so->id}/submit"));

        $this->loginAs(Role::WarehouseStaff);
        $this->assertSame(403, $this->postWithCsrf("/sales-orders/{$so->id}/submit")->status);
        $this->assertSame('Draft', $this->soStatus((int) $so->id));
    }

    // -------------------------------------------------------------- approve

    public function test_approve_by_admin_other_than_creator_sets_approved_by(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]], SalesOrderStatus::PendingApproval);
        $admin = $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/approve");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('success', 'Sales Order disetujui.');
        $this->assertSame('Approved', $this->soStatus((int) $so->id));
        $this->assertSame((int) $admin->id, (int) $this->pdo->query("SELECT approved_by FROM sales_orders WHERE id = {$so->id}")->fetchColumn());
    }

    public function test_approve_is_forbidden_for_sales_even_for_own_order_and_for_warehouse_staff(): void
    {
        $sales = $this->loginAs(Role::Sales);
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]], SalesOrderStatus::PendingApproval);

        $own = $this->postWithCsrf("/sales-orders/{$so->id}/approve");
        $this->assertSame(403, $own->status);

        $this->logout();
        $this->loginAs(Role::WarehouseStaff);
        $this->assertSame(403, $this->postWithCsrf("/sales-orders/{$so->id}/approve")->status);
        $this->assertSame(403, $this->postWithCsrf("/sales-orders/{$so->id}/reject")->status);

        $this->assertSame('PendingApproval', $this->soStatus((int) $so->id));
    }

    public function test_approve_and_reject_own_order_as_admin_violate_segregation_of_duties(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $admin->id, [[$this->makeProduct(), 1]], SalesOrderStatus::PendingApproval);

        foreach (['approve', 'reject'] as $action) {
            $result = $this->postWithCsrf("/sales-orders/{$so->id}/{$action}");

            $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
            $this->assertFlash('error', 'Pembuat SO tidak boleh menyetujui/menolak order miliknya sendiri.');
            $this->assertSame('PendingApproval', $this->soStatus((int) $so->id));
            $this->assertNull($this->pdo->query("SELECT approved_by FROM sales_orders WHERE id = {$so->id}")->fetchColumn() ?: null);
        }
    }

    public function test_approve_requires_pending_approval_status(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]]);
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/approve");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('error', 'SO tidak dalam status menunggu persetujuan.');
        $this->assertSame('Draft', $this->soStatus((int) $so->id));
    }

    public function test_approve_with_invalid_csrf_or_unknown_id(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]], SalesOrderStatus::PendingApproval);
        $this->loginAs(Role::Admin);
        $this->csrfToken();

        $forged = $this->request('POST', "/sales-orders/{$so->id}/approve", ['_csrf' => 'forged']);
        $this->assertRedirectTo("/sales-orders/{$so->id}", $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame('PendingApproval', $this->soStatus((int) $so->id));

        $this->assertSame(404, $this->postWithCsrf('/sales-orders/999999999/approve')->status);

        $this->logout();
        $this->assertRedirectTo('/login', $this->request('POST', "/sales-orders/{$so->id}/approve"));
    }

    // --------------------------------------------------------------- reject

    public function test_reject_by_other_admin_cancels_order(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]], SalesOrderStatus::PendingApproval);
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/reject");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('success', 'Sales Order ditolak.');
        $this->assertSame('Cancelled', $this->soStatus((int) $so->id));
    }

    public function test_reject_requires_pending_status_csrf_and_known_id(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $draft = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]]);
        $pending = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]], SalesOrderStatus::PendingApproval);
        $this->loginAs(Role::Admin);
        $this->csrfToken();

        $wrongStatus = $this->postWithCsrf("/sales-orders/{$draft->id}/reject");
        $this->assertRedirectTo("/sales-orders/{$draft->id}", $wrongStatus);
        $this->assertFlash('error', 'SO tidak dalam status menunggu persetujuan.');
        $this->assertSame('Draft', $this->soStatus((int) $draft->id));

        $forged = $this->request('POST', "/sales-orders/{$pending->id}/reject", ['_csrf' => 'forged']);
        $this->assertRedirectTo("/sales-orders/{$pending->id}", $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame('PendingApproval', $this->soStatus((int) $pending->id));

        $this->assertSame(404, $this->postWithCsrf('/sales-orders/999999999/reject')->status);

        $this->logout();
        $this->loginAs(Role::Sales);
        $this->assertSame(403, $this->postWithCsrf("/sales-orders/{$pending->id}/reject")->status);
    }

    // --------------------------------------------------------------- cancel

    public function test_cancel_by_owner_in_draft_pending_and_approved_status(): void
    {
        $sales = $this->loginAs(Role::Sales);
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();

        foreach ([SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval, SalesOrderStatus::Approved] as $status) {
            $so = $this->seedSo($customer, $warehouse, (int) $sales->id, [[$this->makeProduct(), 1]], $status);

            $result = $this->postWithCsrf("/sales-orders/{$so->id}/cancel");

            $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
            $this->assertFlash('success', 'Sales Order dibatalkan.');
            $this->assertSame('Cancelled', $this->soStatus((int) $so->id));
        }
    }

    public function test_cancel_by_admin_for_another_users_order_is_allowed(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]]);
        $this->loginAs(Role::Admin);

        $this->postWithCsrf("/sales-orders/{$so->id}/cancel");

        $this->assertSame('Cancelled', $this->soStatus((int) $so->id));
    }

    public function test_cancel_by_another_sales_user_is_rejected(): void
    {
        $owner = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $owner->id, [[$this->makeProduct(), 1]]);
        $this->loginAs(Role::Sales);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/cancel");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('error', 'Anda tidak berwenang membatalkan SO ini.');
        $this->assertSame('Draft', $this->soStatus((int) $so->id));
    }

    public function test_cancel_of_fulfilled_or_cancelled_order_is_rejected(): void
    {
        $admin = $this->loginAs(Role::Admin);
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();

        foreach ([SalesOrderStatus::Fulfilled, SalesOrderStatus::Cancelled] as $status) {
            $so = $this->seedSo($customer, $warehouse, (int) $admin->id, [[$this->makeProduct(), 1]], $status);

            $result = $this->postWithCsrf("/sales-orders/{$so->id}/cancel");

            $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
            $this->assertFlash('error', 'SO tidak bisa dibatalkan pada status ini.');
            $this->assertSame($status->value, $this->soStatus((int) $so->id));
        }
    }

    public function test_cancel_csrf_roles_and_unknown_id(): void
    {
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $this->makeWarehouse(), (int) $sales->id, [[$this->makeProduct(), 1]]);
        $this->auth->login($sales);
        $this->csrfToken();

        $forged = $this->request('POST', "/sales-orders/{$so->id}/cancel", ['_csrf' => 'forged']);
        $this->assertRedirectTo("/sales-orders/{$so->id}", $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame('Draft', $this->soStatus((int) $so->id));

        $this->assertSame(404, $this->postWithCsrf('/sales-orders/999999999/cancel')->status);

        $this->logout();
        $this->assertRedirectTo('/login', $this->request('POST', "/sales-orders/{$so->id}/cancel"));

        $this->loginAs(Role::WarehouseStaff);
        $this->assertSame(403, $this->postWithCsrf("/sales-orders/{$so->id}/cancel")->status);
        $this->assertSame('Draft', $this->soStatus((int) $so->id));
    }

    // ---------------------------------------------------------------- issue

    public function test_issue_reduces_stock_writes_ledger_and_fulfils_order(): void
    {
        $warehouse = $this->makeWarehouse();
        $a = $this->makeProduct();
        $b = $this->makeProduct();
        $this->setStock((int) $a->id, (int) $warehouse->id, 10);
        $this->setStock((int) $b->id, (int) $warehouse->id, 4);
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $warehouse, (int) $sales->id, [[$a, 6], [$b, 4]], SalesOrderStatus::Approved);
        $staff = $this->loginAs(Role::WarehouseStaff);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/issue");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertFlash('success', 'Goods issue berhasil dicatat, SO terpenuhi.');
        $this->assertSame('Fulfilled', $this->soStatus((int) $so->id));
        $this->assertSame(4, $this->stockOf((int) $a->id, (int) $warehouse->id));
        $this->assertSame(0, $this->stockOf((int) $b->id, (int) $warehouse->id));
        $ledger = $this->ledgerFor('SalesOrder', (int) $so->id);
        $this->assertCount(2, $ledger);
        $byProduct = [];
        foreach ($ledger as $row) {
            $this->assertSame('Issue', $row['movement_type']);
            $this->assertSame((int) $warehouse->id, (int) $row['warehouse_id']);
            $this->assertSame((int) $staff->id, (int) $row['performed_by']);
            $byProduct[(int) $row['product_id']] = (int) $row['quantity'];
        }
        $this->assertSame([(int) $a->id => 6, (int) $b->id => 4], $byProduct);
        $this->assertStringContainsString('Terpenuhi', $this->get("/sales-orders/{$so->id}")->body);
    }

    public function test_issue_by_admin_is_allowed(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->setStock((int) $product->id, (int) $warehouse->id, 5);
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $warehouse, (int) $sales->id, [[$product, 5]], SalesOrderStatus::Approved);
        $this->loginAs(Role::Admin);

        $this->postWithCsrf("/sales-orders/{$so->id}/issue");

        $this->assertSame('Fulfilled', $this->soStatus((int) $so->id));
        $this->assertSame(0, $this->stockOf((int) $product->id, (int) $warehouse->id));
    }

    public function test_issue_with_insufficient_stock_is_rejected_and_changes_nothing(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->setStock((int) $product->id, (int) $warehouse->id, 2);
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $warehouse, (int) $sales->id, [[$product, 3]], SalesOrderStatus::Approved);
        $this->loginAs(Role::WarehouseStaff);

        $result = $this->postWithCsrf("/sales-orders/{$so->id}/issue");

        $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
        $this->assertStringContainsString("Stok tidak cukup untuk produk {$product->sku}", $this->flashes()['error'] ?? '');
        $this->assertArrayNotHasKey('success', $this->flashes());
        $this->assertSame('Approved', $this->soStatus((int) $so->id));
        $this->assertSame(2, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertSame([], $this->ledgerFor('SalesOrder', (int) $so->id));
    }

    public function test_issue_with_one_short_line_does_not_touch_the_other_lines(): void
    {
        $warehouse = $this->makeWarehouse();
        $enough = $this->makeProduct();
        $short = $this->makeProduct();
        $this->setStock((int) $enough->id, (int) $warehouse->id, 10);
        $this->setStock((int) $short->id, (int) $warehouse->id, 1);
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $warehouse, (int) $sales->id, [[$enough, 5], [$short, 2]], SalesOrderStatus::Approved);
        $this->loginAs(Role::Admin);

        $this->postWithCsrf("/sales-orders/{$so->id}/issue");

        $this->assertStringContainsString($short->sku, $this->flashes()['error'] ?? '');
        $this->assertSame('Approved', $this->soStatus((int) $so->id));
        $this->assertSame(10, $this->stockOf((int) $enough->id, (int) $warehouse->id), 'All-or-nothing: earlier lines are rolled back.');
        $this->assertSame(1, $this->stockOf((int) $short->id, (int) $warehouse->id));
        $this->assertSame([], $this->ledgerFor('SalesOrder', (int) $so->id));
    }

    public function test_issue_requires_approved_status(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->setStock((int) $product->id, (int) $warehouse->id, 10);
        $sales = $this->createUser(Role::Sales)['user'];
        $customer = $this->makeCustomer();
        $this->loginAs(Role::Admin);

        foreach ([SalesOrderStatus::Draft, SalesOrderStatus::PendingApproval, SalesOrderStatus::Fulfilled, SalesOrderStatus::Cancelled] as $status) {
            $so = $this->seedSo($customer, $warehouse, (int) $sales->id, [[$product, 1]], $status);

            $result = $this->postWithCsrf("/sales-orders/{$so->id}/issue");

            $this->assertRedirectTo("/sales-orders/{$so->id}", $result);
            $this->assertFlash('error', 'Goods issue hanya bisa diproses untuk SO berstatus Approved.');
            $this->assertSame($status->value, $this->soStatus((int) $so->id));
        }
        $this->assertSame(10, $this->stockOf((int) $product->id, (int) $warehouse->id));
    }

    public function test_issue_with_invalid_csrf_changes_nothing(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->setStock((int) $product->id, (int) $warehouse->id, 5);
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $warehouse, (int) $sales->id, [[$product, 2]], SalesOrderStatus::Approved);
        $this->loginAs(Role::WarehouseStaff);
        $this->csrfToken();

        $forged = $this->request('POST', "/sales-orders/{$so->id}/issue", ['_csrf' => 'forged']);
        $this->assertRedirectTo("/sales-orders/{$so->id}", $forged);
        $this->assertFlash('error', self::CSRF_MESSAGE);

        unset($_SESSION['_flash']);
        $missing = $this->request('POST', "/sales-orders/{$so->id}/issue");
        $this->assertRedirectTo("/sales-orders/{$so->id}", $missing);
        $this->assertFlash('error', self::CSRF_MESSAGE);

        $this->assertSame('Approved', $this->soStatus((int) $so->id));
        $this->assertSame(5, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertSame([], $this->ledgerFor('SalesOrder', (int) $so->id));
    }

    public function test_issue_is_forbidden_for_sales_unknown_id_is_404_and_guest_is_redirected(): void
    {
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->setStock((int) $product->id, (int) $warehouse->id, 5);
        $sales = $this->createUser(Role::Sales)['user'];
        $so = $this->seedSo($this->makeCustomer(), $warehouse, (int) $sales->id, [[$product, 2]], SalesOrderStatus::Approved);

        $this->assertRedirectTo('/login', $this->request('POST', "/sales-orders/{$so->id}/issue"));

        $this->auth->login($sales);
        $this->assertSame(403, $this->postWithCsrf("/sales-orders/{$so->id}/issue")->status);
        $this->assertSame('Approved', $this->soStatus((int) $so->id));
        $this->assertSame(5, $this->stockOf((int) $product->id, (int) $warehouse->id));

        $this->logout();
        $this->loginAs(Role::Admin);
        $this->assertSame(404, $this->postWithCsrf('/sales-orders/999999999/issue')->status);
    }

    // ----------------------------------------------------------- end to end

    public function test_full_lifecycle_sales_creates_submits_admin_approves_warehouse_issues(): void
    {
        $customer = $this->makeCustomer();
        $warehouse = $this->makeWarehouse();
        $product = $this->makeProduct();
        $this->setStock((int) $product->id, (int) $warehouse->id, 8);

        $sales = $this->loginAs(Role::Sales);
        $this->postWithCsrf('/sales-orders', $this->validForm((int) $customer->id, (int) $warehouse->id, (int) $product->id, '5'));
        $id = (int) $this->pdo->query('SELECT MAX(id) FROM sales_orders')->fetchColumn();
        $this->postWithCsrf("/sales-orders/{$id}/submit");
        $this->assertSame('PendingApproval', $this->soStatus($id));

        $this->assertSame(403, $this->postWithCsrf("/sales-orders/{$id}/approve")->status, 'Creator (Sales) cannot approve.');

        $this->logout();
        $this->loginAs(Role::Admin);
        $this->postWithCsrf("/sales-orders/{$id}/approve");
        $this->assertSame('Approved', $this->soStatus($id));

        $this->logout();
        $this->loginAs(Role::WarehouseStaff);
        $this->postWithCsrf("/sales-orders/{$id}/issue");

        $this->assertSame('Fulfilled', $this->soStatus($id));
        $this->assertSame(3, $this->stockOf((int) $product->id, (int) $warehouse->id));
        $this->assertCount(1, $this->ledgerFor('SalesOrder', $id));
        $this->assertSame((int) $sales->id, (int) $this->pdo->query("SELECT created_by FROM sales_orders WHERE id = {$id}")->fetchColumn());
    }
}
