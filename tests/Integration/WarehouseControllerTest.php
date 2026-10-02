<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Entity\Role;

final class WarehouseControllerTest extends ControllerTestCase
{
    private const CSRF_MESSAGE = 'Sesi tidak valid, silakan coba lagi.';

    private function countByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string,mixed>
     */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM warehouses WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array{id:int,name:string}
     */
    private function makeWarehouse(): array
    {
        $name = 'WH ' . uniqid();
        $stmt = $this->pdo->prepare('INSERT INTO warehouses (name, location, is_active) VALUES (?, ?, 1)');
        $stmt->execute([$name, 'Lokasi Seed']);

        return ['id' => (int) $this->pdo->lastInsertId(), 'name' => $name];
    }

    public function test_index_shows_actions_to_admin(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $result = $this->get('/warehouses', ['q' => $w['name']]);

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString($w['name'], $result->body);
        $this->assertStringContainsString("/warehouses/{$w['id']}/edit", $result->body);
    }

    public function test_index_is_readable_by_non_admin_without_actions(): void
    {
        $w = $this->makeWarehouse();

        foreach ([Role::Sales, Role::WarehouseStaff] as $role) {
            $this->logout();
            $this->loginAs($role);
            $result = $this->get('/warehouses', ['q' => $w['name']]);

            $this->assertSame(200, $result->status);
            $this->assertStringContainsString($w['name'], $result->body);
            $this->assertStringNotContainsString("/warehouses/{$w['id']}/edit", $result->body);
        }
    }

    public function test_index_redirects_guest_to_login(): void
    {
        $this->assertRedirectTo('/login', $this->get('/warehouses'));
    }

    public function test_index_ajax_returns_only_fragment(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $ajax = $this->get('/warehouses', ['q' => $w['name']], true);
        $full = $this->get('/warehouses', ['q' => $w['name']]);

        $this->assertStringContainsString($w['name'], $ajax->body);
        $this->assertStringNotContainsString('<html', $ajax->body);
        $this->assertLessThan(strlen($full->body), strlen($ajax->body));
    }

    public function test_index_search_without_match_shows_empty_message(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/warehouses', ['q' => 'zz_none_' . uniqid()], true);

        $this->assertStringContainsString('Belum ada gudang.', $result->body);
    }

    public function test_create_page_renders_form_for_admin(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/warehouses/create');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Tambah Gudang', $result->body);
        $this->assertStringContainsString('name="location"', $result->body);
    }

    public function test_create_page_is_forbidden_for_sales(): void
    {
        $this->loginAs(Role::Sales);

        $result = $this->get('/warehouses/create');

        $this->assertSame(403, $result->status);
        $this->assertStringContainsString('Akses Ditolak', $result->body);
    }

    public function test_create_page_throws_for_guest_and_wrong_role(): void
    {
        try {
            $this->requestRaw('GET', '/warehouses/create');
            $this->fail('Guest should not reach the form.');
        } catch (UnauthenticatedException) {
            $this->addToAssertionCount(1);
        }

        $this->loginAs(Role::WarehouseStaff);
        $this->expectException(ForbiddenException::class);
        $this->requestRaw('GET', '/warehouses/create');
    }

    public function test_store_creates_warehouse(): void
    {
        $this->loginAs(Role::Admin);
        $name = 'Gudang ' . uniqid();

        $result = $this->postWithCsrf('/warehouses', ['name' => " {$name} ", 'location' => ' Bekasi ']);

        $this->assertRedirectTo('/warehouses', $result);
        $this->assertFlash('success', 'Gudang berhasil dibuat.');
        $this->assertSame(1, $this->countByName($name));
        $stmt = $this->pdo->prepare('SELECT location, is_active FROM warehouses WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Bekasi', $row['location']);
        $this->assertSame(1, (int) $row['is_active']);
    }

    public function test_store_with_empty_fields_returns_errors_and_old_input(): void
    {
        $this->loginAs(Role::Admin);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();

        $result = $this->postWithCsrf('/warehouses', ['name' => '', 'location' => '']);

        $this->assertRedirectTo('/warehouses/create', $result);
        $this->assertSame('Nama gudang wajib diisi.', $this->sessionErrors()['name'] ?? null);
        $this->assertSame('Lokasi wajib diisi.', $this->sessionErrors()['location'] ?? null);
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn());

        $page = $this->get('/warehouses/create');
        $this->assertStringContainsString('Nama gudang wajib diisi.', $page->body);
    }

    public function test_store_keeps_old_input_when_only_location_missing(): void
    {
        $this->loginAs(Role::Admin);
        $name = 'Half ' . uniqid();

        $result = $this->postWithCsrf('/warehouses', ['name' => $name, 'location' => '']);

        $this->assertRedirectTo('/warehouses/create', $result);
        $this->assertSame($name, $this->sessionOldInput()['name'] ?? null);
        $this->assertArrayNotHasKey('name', $this->sessionErrors());
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_with_duplicate_name_is_rejected(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $result = $this->postWithCsrf('/warehouses', ['name' => $w['name'], 'location' => 'X']);

        $this->assertRedirectTo('/warehouses/create', $result);
        $this->assertSame('Nama gudang sudah ada.', $this->sessionErrors()['name'] ?? null);
        $this->assertSame(1, $this->countByName($w['name']));
    }

    public function test_store_with_forged_csrf_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $this->csrfToken();
        $name = 'Forged ' . uniqid();

        $result = $this->request('POST', '/warehouses', ['_csrf' => 'forged', 'name' => $name, 'location' => 'X']);

        $this->assertRedirectTo('/warehouses/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_without_csrf_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $name = 'NoToken ' . uniqid();

        $result = $this->request('POST', '/warehouses', ['name' => $name, 'location' => 'X']);

        $this->assertRedirectTo('/warehouses/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_is_forbidden_for_warehouse_staff(): void
    {
        $this->loginAs(Role::WarehouseStaff);
        $name = 'Sneaky ' . uniqid();

        $result = $this->postWithCsrf('/warehouses', ['name' => $name, 'location' => 'X']);

        $this->assertSame(403, $result->status);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_redirects_guest_to_login(): void
    {
        $name = 'Guest ' . uniqid();

        $result = $this->request('POST', '/warehouses', ['name' => $name, 'location' => 'X']);

        $this->assertRedirectTo('/login', $result);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_edit_page_prefills_warehouse(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $result = $this->get("/warehouses/{$w['id']}/edit");

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Edit Gudang', $result->body);
        $this->assertStringContainsString($w['name'], $result->body);
        $this->assertStringContainsString('Lokasi Seed', $result->body);
    }

    public function test_edit_unknown_warehouse_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->get('/warehouses/999999999/edit')->status);
    }

    public function test_edit_is_forbidden_for_sales(): void
    {
        $w = $this->makeWarehouse();
        $this->loginAs(Role::Sales);

        $this->assertSame(403, $this->get("/warehouses/{$w['id']}/edit")->status);
    }

    public function test_update_changes_row(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();
        $newName = 'Renamed ' . uniqid();

        $result = $this->postWithCsrf("/warehouses/{$w['id']}", ['name' => $newName, 'location' => 'Surabaya']);

        $this->assertRedirectTo('/warehouses', $result);
        $this->assertFlash('success', 'Gudang berhasil diperbarui.');
        $row = $this->row($w['id']);
        $this->assertSame($newName, $row['name']);
        $this->assertSame('Surabaya', $row['location']);
    }

    public function test_update_keeping_own_name_is_allowed(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $result = $this->postWithCsrf("/warehouses/{$w['id']}", ['name' => $w['name'], 'location' => 'Pindah']);

        $this->assertRedirectTo('/warehouses', $result);
        $this->assertSame('Pindah', $this->row($w['id'])['location']);
    }

    public function test_update_with_failure_returns_errors_and_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();
        $other = $this->makeWarehouse();

        $dup = $this->postWithCsrf("/warehouses/{$w['id']}", ['name' => $other['name'], 'location' => 'X']);
        $this->assertRedirectTo("/warehouses/{$w['id']}/edit", $dup);
        $this->assertSame('Nama gudang sudah ada.', $this->sessionErrors()['name'] ?? null);

        $empty = $this->postWithCsrf("/warehouses/{$w['id']}", ['name' => $w['name'], 'location' => '']);
        $this->assertRedirectTo("/warehouses/{$w['id']}/edit", $empty);
        $this->assertSame('Lokasi wajib diisi.', $this->sessionErrors()['location'] ?? null);
        $this->assertSame($w['name'], $this->sessionOldInput()['name'] ?? null);

        $row = $this->row($w['id']);
        $this->assertSame($w['name'], $row['name']);
        $this->assertSame('Lokasi Seed', $row['location']);
    }

    public function test_update_with_forged_csrf_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();
        $this->csrfToken();

        $result = $this->request('POST', "/warehouses/{$w['id']}", ['_csrf' => 'forged', 'name' => 'Hacked', 'location' => 'X']);

        $this->assertRedirectTo("/warehouses/{$w['id']}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($w['name'], $this->row($w['id'])['name']);
    }

    public function test_update_without_csrf_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $result = $this->request('POST', "/warehouses/{$w['id']}", ['name' => 'Hacked', 'location' => 'X']);

        $this->assertRedirectTo("/warehouses/{$w['id']}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($w['name'], $this->row($w['id'])['name']);
    }

    public function test_update_unknown_warehouse_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->postWithCsrf('/warehouses/999999999', ['name' => 'x', 'location' => 'y'])->status);
    }

    public function test_update_is_forbidden_for_sales(): void
    {
        $w = $this->makeWarehouse();
        $this->loginAs(Role::Sales);

        $result = $this->postWithCsrf("/warehouses/{$w['id']}", ['name' => 'Nope', 'location' => 'X']);

        $this->assertSame(403, $result->status);
        $this->assertSame($w['name'], $this->row($w['id'])['name']);
    }

    public function test_toggle_deactivates_then_activates(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $off = $this->postWithCsrf("/warehouses/{$w['id']}/toggle-active", ['active' => '0']);
        $this->assertRedirectTo('/warehouses', $off);
        $this->assertFlash('success', 'Gudang dinonaktifkan.');
        $this->assertSame(0, (int) $this->row($w['id'])['is_active']);

        $on = $this->postWithCsrf("/warehouses/{$w['id']}/toggle-active", ['active' => '1']);
        $this->assertRedirectTo('/warehouses', $on);
        $this->assertFlash('success', 'Gudang diaktifkan.');
        $this->assertSame(1, (int) $this->row($w['id'])['is_active']);
    }

    public function test_toggle_with_forged_csrf_changes_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();
        $this->csrfToken();

        $result = $this->request('POST', "/warehouses/{$w['id']}/toggle-active", ['_csrf' => 'forged', 'active' => '0']);

        $this->assertRedirectTo('/warehouses', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(1, (int) $this->row($w['id'])['is_active']);
    }

    public function test_toggle_without_csrf_changes_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $w = $this->makeWarehouse();

        $result = $this->request('POST', "/warehouses/{$w['id']}/toggle-active", ['active' => '0']);

        $this->assertRedirectTo('/warehouses', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(1, (int) $this->row($w['id'])['is_active']);
    }

    public function test_toggle_unknown_warehouse_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->postWithCsrf('/warehouses/999999999/toggle-active', ['active' => '0'])->status);
    }

    public function test_toggle_is_forbidden_for_warehouse_staff_and_guest(): void
    {
        $w = $this->makeWarehouse();

        $guest = $this->request('POST', "/warehouses/{$w['id']}/toggle-active", ['active' => '0']);
        $this->assertRedirectTo('/login', $guest);

        $this->loginAs(Role::WarehouseStaff);
        $result = $this->postWithCsrf("/warehouses/{$w['id']}/toggle-active", ['active' => '0']);

        $this->assertSame(403, $result->status);
        $this->assertSame(1, (int) $this->row($w['id'])['is_active']);
    }
}
