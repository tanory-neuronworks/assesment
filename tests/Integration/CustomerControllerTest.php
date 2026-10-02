<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Entity\Role;

final class CustomerControllerTest extends ControllerTestCase
{
    private const CSRF_MESSAGE = 'Sesi tidak valid, silakan coba lagi.';

    private function countByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM customers WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array{id:int,name:string}
     */
    private function makeCustomer(bool $active = true): array
    {
        $name = 'Cust ' . uniqid();
        $stmt = $this->pdo->prepare('INSERT INTO customers (name, contact, address, is_active) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, '0812-seed', 'Jl. Seed 1', $active ? 1 : 0]);

        return ['id' => (int) $this->pdo->lastInsertId(), 'name' => $name];
    }

    public function test_index_lists_customers_for_admin_with_actions(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();

        $result = $this->get('/customers', ['q' => $c['name']]);

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString($c['name'], $result->body);
        $this->assertStringContainsString("/customers/{$c['id']}/edit", $result->body);
        $this->assertStringContainsString('<html', $result->body);
    }

    public function test_index_ajax_returns_only_fragment(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();

        $ajax = $this->get('/customers', ['q' => $c['name']], true);
        $full = $this->get('/customers', ['q' => $c['name']]);

        $this->assertStringContainsString($c['name'], $ajax->body);
        $this->assertStringNotContainsString('<html', $ajax->body);
        $this->assertLessThan(strlen($full->body), strlen($ajax->body));
    }

    public function test_index_search_without_match_shows_empty_message(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/customers', ['q' => 'zz_none_' . uniqid()], true);

        $this->assertStringContainsString('Belum ada customer.', $result->body);
    }

    public function test_index_is_forbidden_for_sales_and_redirects_guest(): void
    {
        $this->assertRedirectTo('/login', $this->get('/customers'));

        $this->loginAs(Role::Sales);
        $result = $this->get('/customers');
        $this->assertSame(403, $result->status);
        $this->assertStringContainsString('Akses Ditolak', $result->body);
    }

    public function test_create_page_renders_form_for_admin(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/customers/create');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Tambah Customer', $result->body);
        $this->assertStringContainsString('name="name"', $result->body);
    }

    public function test_create_page_is_forbidden_for_warehouse_staff(): void
    {
        $this->loginAs(Role::WarehouseStaff);

        $this->assertSame(403, $this->get('/customers/create')->status);
    }

    public function test_create_page_throws_for_guest_and_wrong_role(): void
    {
        try {
            $this->requestRaw('GET', '/customers/create');
            $this->fail('Guest should not reach the form.');
        } catch (UnauthenticatedException) {
            $this->addToAssertionCount(1);
        }

        $this->loginAs(Role::Sales);
        $this->expectException(ForbiddenException::class);
        $this->requestRaw('GET', '/customers/create');
    }

    public function test_store_creates_customer_and_redirects_with_flash(): void
    {
        $this->loginAs(Role::Admin);
        $name = 'Baru ' . uniqid();

        $result = $this->postWithCsrf('/customers', ['name' => " {$name} ", 'contact' => '0811', 'address' => 'Jl. Baru']);

        $this->assertRedirectTo('/customers', $result);
        $this->assertFlash('success', 'Customer berhasil dibuat.');
        $this->assertSame(1, $this->countByName($name));
        $stmt = $this->pdo->prepare('SELECT contact, address, is_active FROM customers WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('0811', $row['contact']);
        $this->assertSame('Jl. Baru', $row['address']);
        $this->assertSame(1, (int) $row['is_active']);
    }

    public function test_store_with_empty_name_returns_errors_and_old_input(): void
    {
        $this->loginAs(Role::Admin);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();

        $result = $this->postWithCsrf('/customers', ['name' => '  ', 'contact' => 'keep-contact']);

        $this->assertRedirectTo('/customers/create', $result);
        $this->assertSame('Nama customer wajib diisi.', $this->sessionErrors()['name'] ?? null);
        $this->assertSame('keep-contact', $this->sessionOldInput()['contact'] ?? null);
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn());

        $page = $this->get('/customers/create');
        $this->assertStringContainsString('Nama customer wajib diisi.', $page->body);
        $this->assertStringContainsString('keep-contact', $page->body);
    }

    public function test_store_with_forged_csrf_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $this->csrfToken();
        $name = 'Forged ' . uniqid();

        $result = $this->request('POST', '/customers', ['_csrf' => 'forged', 'name' => $name]);

        $this->assertRedirectTo('/customers/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_without_csrf_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $name = 'NoToken ' . uniqid();

        $result = $this->request('POST', '/customers', ['name' => $name]);

        $this->assertRedirectTo('/customers/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_is_forbidden_for_sales(): void
    {
        $this->loginAs(Role::Sales);
        $name = 'Sneaky ' . uniqid();

        $result = $this->postWithCsrf('/customers', ['name' => $name]);

        $this->assertSame(403, $result->status);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_redirects_guest_to_login(): void
    {
        $name = 'Guest ' . uniqid();

        $result = $this->request('POST', '/customers', ['name' => $name]);

        $this->assertRedirectTo('/login', $result);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_edit_page_prefills_customer(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();

        $result = $this->get("/customers/{$c['id']}/edit");

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Edit Customer', $result->body);
        $this->assertStringContainsString($c['name'], $result->body);
        $this->assertStringContainsString('Jl. Seed 1', $result->body);
    }

    public function test_edit_unknown_customer_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->get('/customers/999999999/edit')->status);
    }

    public function test_edit_is_forbidden_for_sales(): void
    {
        $c = $this->makeCustomer();
        $this->loginAs(Role::Sales);

        $this->assertSame(403, $this->get("/customers/{$c['id']}/edit")->status);
    }

    public function test_update_changes_row(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();
        $newName = 'Renamed ' . uniqid();

        $result = $this->postWithCsrf("/customers/{$c['id']}", ['name' => $newName, 'contact' => '0899', 'address' => 'Alamat baru']);

        $this->assertRedirectTo('/customers', $result);
        $this->assertFlash('success', 'Customer berhasil diperbarui.');
        $row = $this->row($c['id']);
        $this->assertSame($newName, $row['name']);
        $this->assertSame('0899', $row['contact']);
        $this->assertSame('Alamat baru', $row['address']);
    }

    public function test_update_with_empty_name_returns_errors_and_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();

        $result = $this->postWithCsrf("/customers/{$c['id']}", ['name' => '', 'contact' => 'typed']);

        $this->assertRedirectTo("/customers/{$c['id']}/edit", $result);
        $this->assertSame('Nama customer wajib diisi.', $this->sessionErrors()['name'] ?? null);
        $this->assertSame('typed', $this->sessionOldInput()['contact'] ?? null);
        $this->assertSame($c['name'], $this->row($c['id'])['name']);
    }

    public function test_update_with_forged_csrf_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();
        $this->csrfToken();

        $result = $this->request('POST', "/customers/{$c['id']}", ['_csrf' => 'forged', 'name' => 'Hacked']);

        $this->assertRedirectTo("/customers/{$c['id']}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($c['name'], $this->row($c['id'])['name']);
    }

    public function test_update_without_csrf_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();

        $result = $this->request('POST', "/customers/{$c['id']}", ['name' => 'Hacked']);

        $this->assertRedirectTo("/customers/{$c['id']}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($c['name'], $this->row($c['id'])['name']);
    }

    public function test_update_unknown_customer_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->postWithCsrf('/customers/999999999', ['name' => 'x'])->status);
    }

    public function test_update_is_forbidden_for_warehouse_staff(): void
    {
        $c = $this->makeCustomer();
        $this->loginAs(Role::WarehouseStaff);

        $result = $this->postWithCsrf("/customers/{$c['id']}", ['name' => 'Nope']);

        $this->assertSame(403, $result->status);
        $this->assertSame($c['name'], $this->row($c['id'])['name']);
    }

    public function test_toggle_deactivates_then_activates(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();

        $off = $this->postWithCsrf("/customers/{$c['id']}/toggle-active", ['active' => '0']);
        $this->assertRedirectTo('/customers', $off);
        $this->assertFlash('success', 'Customer dinonaktifkan.');
        $this->assertSame(0, (int) $this->row($c['id'])['is_active']);

        $on = $this->postWithCsrf("/customers/{$c['id']}/toggle-active", ['active' => '1']);
        $this->assertRedirectTo('/customers', $on);
        $this->assertFlash('success', 'Customer diaktifkan.');
        $this->assertSame(1, (int) $this->row($c['id'])['is_active']);
    }

    public function test_toggle_with_forged_csrf_changes_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();
        $this->csrfToken();

        $result = $this->request('POST', "/customers/{$c['id']}/toggle-active", ['_csrf' => 'forged', 'active' => '0']);

        $this->assertRedirectTo('/customers', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(1, (int) $this->row($c['id'])['is_active']);
    }

    public function test_toggle_without_csrf_changes_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $c = $this->makeCustomer();

        $result = $this->request('POST', "/customers/{$c['id']}/toggle-active", ['active' => '0']);

        $this->assertRedirectTo('/customers', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(1, (int) $this->row($c['id'])['is_active']);
    }

    public function test_toggle_unknown_customer_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->postWithCsrf('/customers/999999999/toggle-active', ['active' => '0'])->status);
    }

    public function test_toggle_is_forbidden_for_sales_and_changes_nothing(): void
    {
        $c = $this->makeCustomer();
        $this->loginAs(Role::Sales);

        $result = $this->postWithCsrf("/customers/{$c['id']}/toggle-active", ['active' => '0']);

        $this->assertSame(403, $result->status);
        $this->assertSame(1, (int) $this->row($c['id'])['is_active']);
    }

    public function test_toggle_redirects_guest_to_login(): void
    {
        $c = $this->makeCustomer();

        $result = $this->request('POST', "/customers/{$c['id']}/toggle-active", ['active' => '0']);

        $this->assertRedirectTo('/login', $result);
        $this->assertSame(1, (int) $this->row($c['id'])['is_active']);
    }
}
