<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Role;

final class AuthControllerTest extends ControllerTestCase
{
    public function test_show_login_renders_form_with_csrf_token_for_guest(): void
    {
        $result = $this->get('/login');

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Masuk ke akun Anda', $result->body);
        $this->assertStringContainsString('name="login"', $result->body);
        $this->assertStringContainsString('name="_csrf" value="' . $this->csrfToken() . '"', $result->body);
    }

    public function test_show_login_redirects_home_when_already_logged_in(): void
    {
        $this->loginAs(Role::Sales);

        $this->assertRedirectTo('/', $this->get('/login'));
    }

    public function test_login_with_valid_credentials_starts_session_and_redirects_home(): void
    {
        ['user' => $user, 'password' => $password] = $this->createUser(Role::Sales);

        $result = $this->postWithCsrf('/login', ['login' => $user->email, 'password' => $password]);

        $this->assertRedirectTo('/', $result);
        $this->assertSame($user->id, (int) $_SESSION['user_id']);
        $this->assertSame($user->id, $this->auth->user()?->id);
    }

    public function test_login_accepts_username_as_identifier(): void
    {
        ['user' => $user, 'password' => $password] = $this->createUser(Role::Admin);

        $result = $this->postWithCsrf('/login', ['login' => $user->username, 'password' => $password]);

        $this->assertRedirectTo('/', $result);
        $this->assertSame($user->id, (int) $_SESSION['user_id']);
    }

    public function test_login_with_wrong_password_flashes_error_keeps_login_and_stays_logged_out(): void
    {
        ['user' => $user] = $this->createUser(Role::Sales);

        $result = $this->postWithCsrf('/login', ['login' => $user->email, 'password' => 'not-the-password']);

        $this->assertRedirectTo('/login', $result);
        $this->assertFlash('error', 'Email/username atau password salah.');
        $this->assertSame(['login' => $user->email], $this->sessionOldInput());
        $this->assertArrayNotHasKey('user_id', $_SESSION);

        $page = $this->get('/login');
        $this->assertStringContainsString('Email/username atau password salah.', $page->body);
        $this->assertStringContainsString('value="' . $user->email . '"', $page->body);
    }

    public function test_login_with_unknown_user_is_rejected(): void
    {
        $result = $this->postWithCsrf('/login', ['login' => 'nobody_' . uniqid(), 'password' => 'whatever1']);

        $this->assertRedirectTo('/login', $result);
        $this->assertFlash('error', 'Email/username atau password salah.');
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_with_deactivated_user_is_rejected(): void
    {
        ['user' => $user, 'password' => $password] = $this->createUser(Role::Sales);
        $this->userService->setActive((int) $user->id, false);

        $result = $this->postWithCsrf('/login', ['login' => $user->email, 'password' => $password]);

        $this->assertRedirectTo('/login', $result);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_with_invalid_csrf_is_rejected_even_with_correct_password(): void
    {
        ['user' => $user, 'password' => $password] = $this->createUser(Role::Sales);
        $this->csrfToken(); // session has a token, request sends a different one

        $result = $this->request('POST', '/login', ['_csrf' => 'forged', 'login' => $user->email, 'password' => $password]);

        $this->assertRedirectTo('/login', $result);
        $this->assertFlash('error', 'Sesi tidak valid, silakan coba lagi.');
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_login_without_csrf_token_is_rejected(): void
    {
        ['user' => $user, 'password' => $password] = $this->createUser(Role::Sales);

        $result = $this->request('POST', '/login', ['login' => $user->email, 'password' => $password]);

        $this->assertRedirectTo('/login', $result);
        $this->assertFlash('error', 'Sesi tidak valid, silakan coba lagi.');
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function test_logout_clears_session_and_redirects_to_login(): void
    {
        $this->loginAs(Role::Admin);
        $this->assertTrue($this->auth->check());

        $result = $this->request('POST', '/logout');

        $this->assertRedirectTo('/login', $result);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertFalse($this->auth->check());

        $this->assertRedirectTo('/login', $this->get('/'));
    }
}
