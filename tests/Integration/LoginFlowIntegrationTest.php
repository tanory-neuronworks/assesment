<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\Mysql\MysqlUserRepository;
use App\Service\AuthService;
use App\Service\UserService;

final class LoginFlowIntegrationTest extends IntegrationTestCase
{
    public function test_user_created_via_service_can_log_in_end_to_end(): void
    {
        $repo = new MysqlUserRepository($this->pdo);
        $userService = new UserService($repo);
        $authService = new AuthService($repo);
        $email = 'itest_login_' . uniqid() . '@example.test';
        $username = 'itest_login_' . uniqid();

        $userService->create([
            'name' => 'Login Flow User',
            'username' => $username,
            'email' => $email,
            'role' => 'Sales',
            'password' => 'correct-password',
        ]);

        $this->assertNotNull($authService->attempt($email, 'correct-password'));
        $this->assertNotNull($authService->attempt($username, 'correct-password'));
        $this->assertNull($authService->attempt($email, 'wrong-password'));
    }

    public function test_deactivated_user_cannot_log_in_even_with_correct_password(): void
    {
        $repo = new MysqlUserRepository($this->pdo);
        $userService = new UserService($repo);
        $authService = new AuthService($repo);
        $email = 'itest_deactivated_login_' . uniqid() . '@example.test';

        $user = $userService->create([
            'name' => 'Deactivated User',
            'username' => 'itest_deactivated_' . uniqid(),
            'email' => $email,
            'role' => 'WarehouseStaff',
            'password' => 'correct-password',
        ]);
        $userService->setActive((int) $user->id, false);

        $this->assertNull($authService->attempt($email, 'correct-password'));
    }
}
