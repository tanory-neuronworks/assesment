<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Session;
use App\Service\AuthService;

final class AuthController extends Controller
{
    private const LOGIN_PATH = '/login';

    public function __construct(Auth $auth, private readonly AuthService $authService)
    {
        parent::__construct($auth);
    }

    public function showLogin(): string
    {
        if ($this->auth->check()) {
            return $this->redirect('/');
        }

        return $this->view('auth.login', ['title' => 'Login']);
    }

    public function login(Request $request): string
    {
        if (($rejected = $this->rejectInvalidCsrf($request, self::LOGIN_PATH)) !== null) {
            return $rejected;
        }

        $identifier = (string) $request->post('login', '');
        $password = (string) $request->post('password', '');

        $user = $this->authService->attempt($identifier, $password);

        if ($user === null) {
            Session::flash('error', 'Email/username atau password salah.');
            Session::setOldInput(['login' => $identifier]);

            return $this->redirect(self::LOGIN_PATH);
        }

        $this->auth->login($user);

        return $this->redirect('/');
    }

    public function logout(): string
    {
        $this->auth->logout();

        return $this->redirect(self::LOGIN_PATH);
    }
}
