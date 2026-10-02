<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Role;
use App\Entity\User;
use App\Repository\InMemory\InMemoryUserRepository;
use App\Service\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private function seedUser(InMemoryUserRepository $repo, string $email, string $password, bool $active = true, ?string $username = null): User
    {
        $user = new User(
            id: null,
            name: 'Test User',
            username: $username ?? strstr($email, '@', true),
            email: $email,
            passwordHash: password_hash($password, PASSWORD_DEFAULT),
            role: Role::Sales,
            isActive: $active,
        );
        $repo->create($user);

        return $user;
    }

    public function test_it_authenticates_with_correct_credentials(): void
    {
        $repo = new InMemoryUserRepository();
        $this->seedUser($repo, 'user@example.test', 'correct-password');

        $result = (new AuthService($repo))->attempt('user@example.test', 'correct-password');

        $this->assertNotNull($result);
        $this->assertSame('user@example.test', $result->email);
    }

    public function test_it_authenticates_with_username_instead_of_email(): void
    {
        $repo = new InMemoryUserRepository();
        $this->seedUser($repo, 'user@example.test', 'correct-password', username: 'someuser');

        $result = (new AuthService($repo))->attempt('someuser', 'correct-password');

        $this->assertNotNull($result);
        $this->assertSame('someuser', $result->username);
    }

    public function test_it_rejects_wrong_password(): void
    {
        $repo = new InMemoryUserRepository();
        $this->seedUser($repo, 'user@example.test', 'correct-password');

        $result = (new AuthService($repo))->attempt('user@example.test', 'wrong-password');

        $this->assertNull($result);
    }

    public function test_it_rejects_unknown_email(): void
    {
        $repo = new InMemoryUserRepository();

        $result = (new AuthService($repo))->attempt('missing@example.test', 'whatever');

        $this->assertNull($result);
    }

    public function test_it_rejects_inactive_user_even_with_correct_password(): void
    {
        $repo = new InMemoryUserRepository();
        $this->seedUser($repo, 'inactive@example.test', 'correct-password', active: false);

        $result = (new AuthService($repo))->attempt('inactive@example.test', 'correct-password');

        $this->assertNull($result);
    }
}
