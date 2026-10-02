<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Auth;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\InMemory\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class AuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function makeAuthAs(Role $role): Auth
    {
        $repo = new InMemoryUserRepository();
        $user = new User(null, 'Test User', 'testuser', 'user@example.test', 'hash', $role, true);
        $id = $repo->create($user);
        $_SESSION['user_id'] = $id;

        return new Auth($repo);
    }

    public function test_it_denies_non_admin_from_admin_only_action(): void
    {
        $auth = $this->makeAuthAs(Role::Sales);

        $this->expectException(ForbiddenException::class);
        $auth->requireRole(Role::Admin);
    }

    public function test_it_allows_matching_role(): void
    {
        $auth = $this->makeAuthAs(Role::Admin);

        $user = $auth->requireRole(Role::Admin);

        $this->assertSame(Role::Admin, $user->role);
    }

    public function test_it_allows_any_of_multiple_roles(): void
    {
        $auth = $this->makeAuthAs(Role::WarehouseStaff);

        $user = $auth->requireRole(Role::Admin, Role::WarehouseStaff);

        $this->assertSame(Role::WarehouseStaff, $user->role);
    }

    public function test_it_throws_unauthenticated_when_no_session(): void
    {
        $repo = new InMemoryUserRepository();
        $auth = new Auth($repo);

        $this->expectException(UnauthenticatedException::class);
        $auth->requireLogin();
    }
}
