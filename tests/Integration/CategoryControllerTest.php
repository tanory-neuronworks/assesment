<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Entity\Category;
use App\Entity\Role;

final class CategoryControllerTest extends ControllerTestCase
{
    private const CSRF_MESSAGE = 'Sesi tidak valid, silakan coba lagi.';

    private function countByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function makeCategory(string $description = 'seed'): Category
    {
        return $this->categoryService->create(['name' => 'Cat ' . uniqid(), 'description' => $description]);
    }

    public function test_create_page_renders_form_for_admin(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/categories/create');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Tambah Kategori', $result->body);
        $this->assertStringContainsString('name="name"', $result->body);
    }

    public function test_create_page_is_forbidden_for_sales(): void
    {
        $this->loginAs(Role::Sales);

        $result = $this->get('/categories/create');

        $this->assertSame(403, $result->status);
        $this->assertStringContainsString('Akses Ditolak', $result->body);
    }

    public function test_admin_routes_throw_for_wrong_role(): void
    {
        $this->loginAs(Role::WarehouseStaff);

        $this->expectException(ForbiddenException::class);
        $this->requestRaw('GET', '/categories/create');
    }

    public function test_admin_routes_throw_for_guest(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->requestRaw('GET', '/categories/create');
    }

    public function test_store_creates_category_flashes_success_and_redirects_to_list(): void
    {
        $this->loginAs(Role::Admin);
        $name = 'Elektronik ' . uniqid();

        $result = $this->postWithCsrf('/categories', ['name' => $name, 'description' => '  Barang elektronik  ']);

        $this->assertRedirectTo('/categories', $result);
        $this->assertFlash('success', 'Kategori berhasil dibuat.');
        $this->assertSame(1, $this->countByName($name));

        $list = $this->get('/categories', ['q' => $name]);
        $this->assertStringContainsString($name, $list->body);
        $this->assertStringContainsString('Barang elektronik', $list->body);
        $this->assertStringContainsString('Kategori berhasil dibuat.', $list->body);
    }

    public function test_store_with_invalid_csrf_redirects_with_error_and_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $this->csrfToken();
        $name = 'Forged ' . uniqid();

        $result = $this->request('POST', '/categories', ['_csrf' => 'forged', 'name' => $name]);

        $this->assertRedirectTo('/categories/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_without_csrf_token_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $name = 'NoToken ' . uniqid();

        $result = $this->request('POST', '/categories', ['name' => $name]);

        $this->assertRedirectTo('/categories/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_store_with_empty_name_redirects_back_with_errors_and_old_input(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf('/categories', ['name' => '   ', 'description' => 'keep me']);

        $this->assertRedirectTo('/categories/create', $result);
        $this->assertSame('Nama kategori wajib diisi.', $this->sessionErrors()['name'] ?? null);
        $this->assertSame('keep me', $this->sessionOldInput()['description'] ?? null);

        $page = $this->get('/categories/create');
        $this->assertStringContainsString('Nama kategori wajib diisi.', $page->body);
        $this->assertStringContainsString('keep me', $page->body);
    }

    public function test_store_with_duplicate_name_is_rejected_and_not_duplicated(): void
    {
        $this->loginAs(Role::Admin);
        $existing = $this->makeCategory();

        $result = $this->postWithCsrf('/categories', ['name' => $existing->name]);

        $this->assertRedirectTo('/categories/create', $result);
        $this->assertSame('Nama kategori sudah ada.', $this->sessionErrors()['name'] ?? null);
        $this->assertSame(1, $this->countByName($existing->name));
    }

    public function test_store_is_forbidden_for_sales_and_creates_nothing(): void
    {
        $this->loginAs(Role::Sales);
        $name = 'Sneaky ' . uniqid();

        $result = $this->postWithCsrf('/categories', ['name' => $name]);

        $this->assertSame(403, $result->status);
        $this->assertSame(0, $this->countByName($name));
    }

    public function test_edit_page_prefills_category(): void
    {
        $this->loginAs(Role::Admin);
        $cat = $this->makeCategory('deskripsi lama');

        $result = $this->get("/categories/{$cat->id}/edit");

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Edit Kategori', $result->body);
        $this->assertStringContainsString($cat->name, $result->body);
        $this->assertStringContainsString('deskripsi lama', $result->body);
    }

    public function test_edit_unknown_category_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->get('/categories/999999999/edit')->status);
    }

    public function test_update_changes_row_and_redirects_to_list(): void
    {
        $this->loginAs(Role::Admin);
        $cat = $this->makeCategory();
        $newName = 'Renamed ' . uniqid();

        $result = $this->postWithCsrf("/categories/{$cat->id}", ['name' => $newName, 'description' => 'baru']);

        $this->assertRedirectTo('/categories', $result);
        $this->assertFlash('success', 'Kategori berhasil diperbarui.');
        $reloaded = $this->categoryService->find((int) $cat->id);
        $this->assertSame($newName, $reloaded->name);
        $this->assertSame('baru', $reloaded->description);
    }

    public function test_update_with_invalid_csrf_does_not_change_row(): void
    {
        $this->loginAs(Role::Admin);
        $cat = $this->makeCategory();
        $this->csrfToken();

        $result = $this->request('POST', "/categories/{$cat->id}", ['_csrf' => 'forged', 'name' => 'Hacked']);

        $this->assertRedirectTo("/categories/{$cat->id}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($cat->name, $this->categoryService->find((int) $cat->id)->name);
    }

    public function test_update_with_empty_name_redirects_back_with_errors_and_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $cat = $this->makeCategory();

        $result = $this->postWithCsrf("/categories/{$cat->id}", ['name' => '']);

        $this->assertRedirectTo("/categories/{$cat->id}/edit", $result);
        $this->assertSame('Nama kategori wajib diisi.', $this->sessionErrors()['name'] ?? null);
        $this->assertSame($cat->name, $this->categoryService->find((int) $cat->id)->name);
    }

    public function test_update_unknown_category_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->postWithCsrf('/categories/999999999', ['name' => 'x'])->status);
    }

    public function test_update_is_forbidden_for_warehouse_staff(): void
    {
        $this->loginAs(Role::WarehouseStaff);
        $cat = $this->makeCategory();

        $result = $this->postWithCsrf("/categories/{$cat->id}", ['name' => 'Nope']);

        $this->assertSame(403, $result->status);
        $this->assertSame($cat->name, $this->categoryService->find((int) $cat->id)->name);
    }

    public function test_index_requires_login(): void
    {
        $this->assertRedirectTo('/login', $this->get('/categories'));
    }

    public function test_index_hides_edit_links_from_non_admin_and_shows_them_to_admin(): void
    {
        $cat = $this->makeCategory();

        $this->loginAs(Role::Sales);
        $asSales = $this->get('/categories', ['q' => $cat->name]);
        $this->assertStringContainsString($cat->name, $asSales->body);
        $this->assertStringNotContainsString("/categories/{$cat->id}/edit", $asSales->body);

        $this->logout();
        $this->loginAs(Role::Admin);
        $asAdmin = $this->get('/categories', ['q' => $cat->name]);
        $this->assertStringContainsString("/categories/{$cat->id}/edit", $asAdmin->body);
    }

    public function test_index_ajax_returns_only_the_results_fragment(): void
    {
        $this->loginAs(Role::Admin);
        $cat = $this->makeCategory();

        $ajax = $this->get('/categories', ['q' => $cat->name], true);
        $full = $this->get('/categories', ['q' => $cat->name]);

        $this->assertStringContainsString($cat->name, $ajax->body);
        $this->assertStringNotContainsString('<html', $ajax->body);
        $this->assertStringContainsString('<html', $full->body);
        $this->assertLessThan(strlen($full->body), strlen($ajax->body));
    }

    public function test_index_search_with_no_match_shows_empty_message(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/categories', ['q' => 'zz_no_such_' . uniqid()], true);

        $this->assertStringContainsString('Belum ada kategori.', $result->body);
    }
}
