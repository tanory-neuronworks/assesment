<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\Mysql\MysqlUserRepository;
use PDOException;

final class UserRepositoryIntegrationTest extends IntegrationTestCase
{
    public function test_it_creates_and_finds_a_user_by_email(): void
    {
        $repo = new MysqlUserRepository($this->pdo);
        $email = 'itest_' . uniqid() . '@example.test';
        $username = 'itest_' . uniqid();

        $repo->create(new User(
            id: null,
            name: 'Integration Test User',
            username: $username,
            email: $email,
            passwordHash: password_hash('secret123', PASSWORD_DEFAULT),
            role: Role::Sales,
            isActive: true,
        ));

        $found = $repo->findByEmail($email);

        $this->assertNotNull($found);
        $this->assertSame($email, $found->email);
        $this->assertTrue($found->isActive);
    }

    public function test_it_finds_a_user_by_username(): void
    {
        $repo = new MysqlUserRepository($this->pdo);
        $username = 'itest_uname_' . uniqid();

        $repo->create(new User(
            id: null,
            name: 'Integration Test User',
            username: $username,
            email: 'itest_uname_' . uniqid() . '@example.test',
            passwordHash: password_hash('secret123', PASSWORD_DEFAULT),
            role: Role::Sales,
            isActive: true,
        ));

        $found = $repo->findByUsername($username);

        $this->assertNotNull($found);
        $this->assertSame($username, $found->username);
    }

    public function test_it_enforces_unique_email_constraint(): void
    {
        $repo = new MysqlUserRepository($this->pdo);
        $email = 'itest_dup_' . uniqid() . '@example.test';

        $repo->create(new User(null, 'User One', 'itest_dup_a_' . uniqid(), $email, password_hash('x', PASSWORD_DEFAULT), Role::Sales, true));

        $this->expectException(PDOException::class);
        $repo->create(new User(null, 'User Two', 'itest_dup_b_' . uniqid(), $email, password_hash('y', PASSWORD_DEFAULT), Role::Sales, true));
    }

    public function test_deactivating_a_user_is_reflected_immediately(): void
    {
        $repo = new MysqlUserRepository($this->pdo);
        $email = 'itest_deactivate_' . uniqid() . '@example.test';

        $id = $repo->create(new User(null, 'To Deactivate', 'itest_deactivate_' . uniqid(), $email, password_hash('x', PASSWORD_DEFAULT), Role::WarehouseStaff, true));

        $repo->setActive($id, false);

        $found = $repo->findById($id);
        $this->assertNotNull($found);
        $this->assertFalse($found->isActive);
    }
}
