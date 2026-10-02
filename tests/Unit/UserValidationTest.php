<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\ValidationException;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Service\UserService;
use PHPUnit\Framework\TestCase;

final class UserValidationTest extends TestCase
{
    public function test_it_rejects_invalid_email_format(): void
    {
        $service = new UserService(new InMemoryUserRepository());

        try {
            $service->create([
                'name' => 'Budi',
                'email' => 'not-an-email',
                'role' => 'Sales',
                'password' => 'password123',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
    }

    public function test_it_rejects_duplicate_email(): void
    {
        $service = new UserService(new InMemoryUserRepository());
        $payload = [
            'name' => 'Budi',
            'username' => 'budi',
            'email' => 'budi@example.test',
            'role' => 'Sales',
            'password' => 'password123',
        ];

        $service->create($payload);

        try {
            $service->create($payload);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
    }

    public function test_it_rejects_invalid_role(): void
    {
        $service = new UserService(new InMemoryUserRepository());

        try {
            $service->create([
                'name' => 'Budi',
                'email' => 'budi2@example.test',
                'role' => 'SuperAdmin',
                'password' => 'password123',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }
    }

    public function test_it_rejects_short_password_on_create(): void
    {
        $service = new UserService(new InMemoryUserRepository());

        try {
            $service->create([
                'name' => 'Budi',
                'email' => 'budi3@example.test',
                'role' => 'Sales',
                'password' => 'short',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }
    }

    public function test_it_creates_user_when_data_is_valid(): void
    {
        $service = new UserService(new InMemoryUserRepository());

        $user = $service->create([
            'name' => 'Budi',
            'username' => 'budi4',
            'email' => 'budi4@example.test',
            'role' => 'WarehouseStaff',
            'password' => 'password123',
        ]);

        $this->assertSame('budi4@example.test', $user->email);
        $this->assertTrue($user->isActive);
    }
}
