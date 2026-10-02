<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Entity\Role;
use App\Entity\User;

final class UserControllerTest extends ControllerTestCase
{
    private const CSRF_MESSAGE = 'Sesi tidak valid, silakan coba lagi.';

    /**
     * @return array<string,string>
     */
    private function payload(string $tag, array $override = []): array
    {
        return $override + [
            'name' => 'Pengguna ' . $tag,
            'username' => 'usr_' . $tag,
            'email' => 'usr_' . $tag . '@example.test',
            'role' => Role::Sales->value,
            'password' => 'password-123',
        ];
    }

    private function countByUsername(string $username): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);

        return (int) $stmt->fetchColumn();
    }

    private function seedUser(Role $role = Role::Sales): User
    {
        $tag = uniqid();

        return $this->userService->create($this->payload($tag, ['role' => $role->value]));
    }

    public function test_index_lists_users_for_admin(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();

        $result = $this->get('/users', ['q' => $u->username]);

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString($u->username, $result->body);
        $this->assertStringContainsString("/users/{$u->id}/edit", $result->body);
    }

    public function test_index_ajax_returns_only_fragment(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();

        $ajax = $this->get('/users', ['q' => $u->username], true);
        $full = $this->get('/users', ['q' => $u->username]);

        $this->assertStringContainsString($u->email, $ajax->body);
        $this->assertStringNotContainsString('<html', $ajax->body);
        $this->assertLessThan(strlen($full->body), strlen($ajax->body));
    }

    public function test_index_search_without_match_shows_empty_message(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/users', ['q' => 'zz_none_' . uniqid()], true);

        $this->assertStringContainsString('Belum ada user.', $result->body);
    }

    public function test_index_is_forbidden_for_non_admin_and_redirects_guest(): void
    {
        $this->assertRedirectTo('/login', $this->get('/users'));

        $this->loginAs(Role::WarehouseStaff);
        $result = $this->get('/users');
        $this->assertSame(403, $result->status);
        $this->assertStringContainsString('Akses Ditolak', $result->body);
    }

    public function test_create_page_lists_all_roles(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/users/create');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Tambah User', $result->body);
        foreach (Role::cases() as $role) {
            $this->assertStringContainsString('value="' . $role->value . '"', $result->body);
        }
    }

    public function test_create_page_is_forbidden_for_sales(): void
    {
        $this->loginAs(Role::Sales);

        $this->assertSame(403, $this->get('/users/create')->status);
    }

    public function test_create_page_throws_for_guest_and_wrong_role(): void
    {
        try {
            $this->requestRaw('GET', '/users/create');
            $this->fail('Guest should not reach the form.');
        } catch (UnauthenticatedException) {
            $this->addToAssertionCount(1);
        }

        $this->loginAs(Role::Sales);
        $this->expectException(ForbiddenException::class);
        $this->requestRaw('GET', '/users/create');
    }

    public function test_store_creates_user_with_hashed_password_and_normalized_email(): void
    {
        $this->loginAs(Role::Admin);
        $tag = uniqid();
        $data = $this->payload($tag, ['email' => 'USR_' . $tag . '@Example.Test', 'role' => Role::WarehouseStaff->value]);

        $result = $this->postWithCsrf('/users', $data);

        $this->assertRedirectTo('/users', $result);
        $this->assertFlash('success', 'User berhasil dibuat.');
        $created = $this->userRepository->findByUsername($data['username']);
        $this->assertNotNull($created);
        $this->assertSame('usr_' . $tag . '@example.test', $created->email);
        $this->assertSame(Role::WarehouseStaff, $created->role);
        $this->assertTrue($created->isActive);
        $this->assertNotSame('password-123', $created->passwordHash);
        $this->assertTrue(password_verify('password-123', $created->passwordHash));
    }

    public function test_store_with_empty_input_returns_all_errors_and_old_input(): void
    {
        $this->loginAs(Role::Admin);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $result = $this->postWithCsrf('/users', ['name' => '', 'username' => '', 'email' => '', 'role' => '', 'password' => '']);

        $this->assertRedirectTo('/users/create', $result);
        $errors = $this->sessionErrors();
        $this->assertSame('Nama wajib diisi.', $errors['name'] ?? null);
        $this->assertSame('Username wajib diisi.', $errors['username'] ?? null);
        $this->assertSame('Email wajib diisi.', $errors['email'] ?? null);
        $this->assertSame('Role tidak valid.', $errors['role'] ?? null);
        $this->assertSame('Password wajib diisi.', $errors['password'] ?? null);
        $this->assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_store_with_invalid_role_is_rejected(): void
    {
        $this->loginAs(Role::Admin);
        $data = $this->payload(uniqid(), ['role' => 'SuperAdmin']);

        $result = $this->postWithCsrf('/users', $data);

        $this->assertRedirectTo('/users/create', $result);
        $this->assertSame('Role tidak valid.', $this->sessionErrors()['role'] ?? null);
        $this->assertSame('SuperAdmin', $this->sessionOldInput()['role'] ?? null);
        $this->assertSame(0, $this->countByUsername($data['username']));
    }

    public function test_store_with_format_errors_is_rejected_and_page_shows_them(): void
    {
        $this->loginAs(Role::Admin);
        $data = $this->payload(uniqid(), ['username' => 'a b', 'email' => 'not-an-email', 'password' => 'short']);

        $result = $this->postWithCsrf('/users', $data);

        $this->assertRedirectTo('/users/create', $result);
        $errors = $this->sessionErrors();
        $this->assertStringContainsString('Username 3-60 karakter', $errors['username'] ?? '');
        $this->assertSame('Format email tidak valid.', $errors['email'] ?? null);
        $this->assertSame('Password minimal 8 karakter.', $errors['password'] ?? null);
        $this->assertSame(0, $this->countByUsername('a b'));

        $page = $this->get('/users/create');
        $this->assertStringContainsString('Format email tidak valid.', $page->body);
    }

    public function test_store_rejects_duplicate_username_and_email(): void
    {
        $this->loginAs(Role::Admin);
        $existing = $this->seedUser();

        $dupUser = $this->postWithCsrf('/users', $this->payload(uniqid(), ['username' => $existing->username]));
        $this->assertRedirectTo('/users/create', $dupUser);
        $this->assertSame('Username sudah dipakai.', $this->sessionErrors()['username'] ?? null);
        $this->assertSame(1, $this->countByUsername($existing->username));

        $tag = uniqid();
        $dupMail = $this->postWithCsrf('/users', $this->payload($tag, ['email' => strtoupper($existing->email)]));
        $this->assertRedirectTo('/users/create', $dupMail);
        $this->assertSame('Email sudah terdaftar.', $this->sessionErrors()['email'] ?? null);
        $this->assertSame(0, $this->countByUsername('usr_' . $tag));
    }

    public function test_store_with_forged_csrf_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $this->csrfToken();
        $data = $this->payload(uniqid());

        $result = $this->request('POST', '/users', ['_csrf' => 'forged'] + $data);

        $this->assertRedirectTo('/users/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByUsername($data['username']));
    }

    public function test_store_without_csrf_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $data = $this->payload(uniqid());

        $result = $this->request('POST', '/users', $data);

        $this->assertRedirectTo('/users/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countByUsername($data['username']));
    }

    public function test_store_is_forbidden_for_sales_and_redirects_guest(): void
    {
        $data = $this->payload(uniqid(), ['role' => Role::Admin->value]);

        $this->assertRedirectTo('/login', $this->request('POST', '/users', $data));

        $this->loginAs(Role::Sales);
        $result = $this->postWithCsrf('/users', $data);

        $this->assertSame(403, $result->status);
        $this->assertSame(0, $this->countByUsername($data['username']));
    }

    public function test_edit_page_prefills_user_and_selects_role(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser(Role::WarehouseStaff);

        $result = $this->get("/users/{$u->id}/edit");

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Edit User', $result->body);
        $this->assertStringContainsString($u->username, $result->body);
        $this->assertStringContainsString($u->email, $result->body);
        $this->assertStringContainsString('selected', $result->body);
    }

    public function test_edit_unknown_user_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->get('/users/999999999/edit')->status);
    }

    public function test_edit_is_forbidden_for_warehouse_staff(): void
    {
        $u = $this->seedUser();
        $this->loginAs(Role::WarehouseStaff);

        $this->assertSame(403, $this->get("/users/{$u->id}/edit")->status);
    }

    public function test_update_changes_profile_and_role_keeping_password_when_blank(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();
        $oldHash = $u->passwordHash;
        $tag = uniqid();

        $result = $this->postWithCsrf("/users/{$u->id}", [
            'name' => 'Diubah ' . $tag,
            'username' => 'chg_' . $tag,
            'email' => 'chg_' . $tag . '@example.test',
            'role' => Role::Admin->value,
            'password' => '',
        ]);

        $this->assertRedirectTo('/users', $result);
        $this->assertFlash('success', 'User berhasil diperbarui.');
        $reloaded = $this->userRepository->findById((int) $u->id);
        $this->assertSame('Diubah ' . $tag, $reloaded->name);
        $this->assertSame('chg_' . $tag, $reloaded->username);
        $this->assertSame(Role::Admin, $reloaded->role);
        $this->assertSame($oldHash, $reloaded->passwordHash);
    }

    public function test_update_with_new_password_rehashes(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();

        $result = $this->postWithCsrf("/users/{$u->id}", $this->payload(uniqid(), [
            'username' => $u->username,
            'email' => $u->email,
            'name' => $u->name,
            'password' => 'brand-new-pass',
        ]));

        $this->assertRedirectTo('/users', $result);
        $reloaded = $this->userRepository->findById((int) $u->id);
        $this->assertTrue(password_verify('brand-new-pass', $reloaded->passwordHash));
    }

    public function test_update_failures_return_errors_and_keep_row(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();
        $other = $this->seedUser();
        $base = ['name' => $u->name, 'username' => $u->username, 'email' => $u->email, 'role' => Role::Sales->value];

        $dupUser = $this->postWithCsrf("/users/{$u->id}", ['username' => $other->username] + $base);
        $this->assertRedirectTo("/users/{$u->id}/edit", $dupUser);
        $this->assertSame('Username sudah dipakai.', $this->sessionErrors()['username'] ?? null);

        $dupMail = $this->postWithCsrf("/users/{$u->id}", ['email' => $other->email] + $base);
        $this->assertSame('Email sudah terdaftar.', $this->sessionErrors()['email'] ?? null);
        $this->assertRedirectTo("/users/{$u->id}/edit", $dupMail);

        $badRole = $this->postWithCsrf("/users/{$u->id}", ['role' => 'Root'] + $base);
        $this->assertRedirectTo("/users/{$u->id}/edit", $badRole);
        $this->assertSame('Role tidak valid.', $this->sessionErrors()['role'] ?? null);

        $badPass = $this->postWithCsrf("/users/{$u->id}", ['password' => 'short'] + $base);
        $this->assertSame('Password minimal 8 karakter.', $this->sessionErrors()['password'] ?? null);
        $this->assertSame('short', $this->sessionOldInput()['password'] ?? null);
        $this->assertRedirectTo("/users/{$u->id}/edit", $badPass);

        $reloaded = $this->userRepository->findById((int) $u->id);
        $this->assertSame($u->username, $reloaded->username);
        $this->assertSame($u->email, $reloaded->email);
        $this->assertSame(Role::Sales, $reloaded->role);
    }

    public function test_update_keeping_own_username_and_email_is_allowed(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();

        $result = $this->postWithCsrf("/users/{$u->id}", [
            'name' => 'Nama Baru',
            'username' => $u->username,
            'email' => $u->email,
            'role' => Role::Sales->value,
        ]);

        $this->assertRedirectTo('/users', $result);
        $this->assertSame('Nama Baru', $this->userRepository->findById((int) $u->id)->name);
    }

    public function test_update_with_forged_csrf_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();
        $this->csrfToken();

        $result = $this->request('POST', "/users/{$u->id}", ['_csrf' => 'forged'] + $this->payload(uniqid(), ['role' => Role::Admin->value]));

        $this->assertRedirectTo("/users/{$u->id}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $reloaded = $this->userRepository->findById((int) $u->id);
        $this->assertSame($u->username, $reloaded->username);
        $this->assertSame(Role::Sales, $reloaded->role);
    }

    public function test_update_without_csrf_keeps_row(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();

        $result = $this->request('POST', "/users/{$u->id}", $this->payload(uniqid(), ['role' => Role::Admin->value]));

        $this->assertRedirectTo("/users/{$u->id}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(Role::Sales, $this->userRepository->findById((int) $u->id)->role);
    }

    public function test_update_unknown_user_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->postWithCsrf('/users/999999999', $this->payload(uniqid()))->status);
    }

    public function test_update_is_forbidden_for_sales_and_cannot_escalate_role(): void
    {
        $sales = $this->loginAs(Role::Sales);

        $result = $this->postWithCsrf("/users/{$sales->id}", [
            'name' => $sales->name,
            'username' => $sales->username,
            'email' => $sales->email,
            'role' => Role::Admin->value,
        ]);

        $this->assertSame(403, $result->status);
        $this->assertSame(Role::Sales, $this->userRepository->findById((int) $sales->id)->role);
    }

    public function test_toggle_deactivates_then_activates(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();

        $off = $this->postWithCsrf("/users/{$u->id}/toggle-active", ['active' => '0']);
        $this->assertRedirectTo('/users', $off);
        $this->assertFlash('success', 'User dinonaktifkan.');
        $this->assertFalse($this->userRepository->findById((int) $u->id)->isActive);

        $on = $this->postWithCsrf("/users/{$u->id}/toggle-active", ['active' => '1']);
        $this->assertRedirectTo('/users', $on);
        $this->assertFlash('success', 'User diaktifkan.');
        $this->assertTrue($this->userRepository->findById((int) $u->id)->isActive);
    }

    public function test_toggle_with_forged_csrf_changes_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();
        $this->csrfToken();

        $result = $this->request('POST', "/users/{$u->id}/toggle-active", ['_csrf' => 'forged', 'active' => '0']);

        $this->assertRedirectTo('/users', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertTrue($this->userRepository->findById((int) $u->id)->isActive);
    }

    public function test_toggle_without_csrf_changes_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $u = $this->seedUser();

        $result = $this->request('POST', "/users/{$u->id}/toggle-active", ['active' => '0']);

        $this->assertRedirectTo('/users', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertTrue($this->userRepository->findById((int) $u->id)->isActive);
    }

    public function test_toggle_unknown_user_is_404(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertSame(404, $this->postWithCsrf('/users/999999999/toggle-active', ['active' => '0'])->status);
    }

    public function test_toggle_is_forbidden_for_non_admin_and_guest(): void
    {
        $u = $this->seedUser();

        $this->assertRedirectTo('/login', $this->request('POST', "/users/{$u->id}/toggle-active", ['active' => '0']));

        $this->loginAs(Role::WarehouseStaff);
        $result = $this->postWithCsrf("/users/{$u->id}/toggle-active", ['active' => '0']);

        $this->assertSame(403, $result->status);
        $this->assertTrue($this->userRepository->findById((int) $u->id)->isActive);
    }
}
