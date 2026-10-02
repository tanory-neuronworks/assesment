<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    protected const CSRF_ERROR = 'Sesi tidak valid, silakan coba lagi.';

    public function __construct(protected readonly Auth $auth)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function view(string $template, array $data = []): string
    {
        $data['user'] = $data['user'] ?? $this->auth->user();
        $data['errors'] = $data['errors'] ?? Session::pullErrors();
        $data['old'] = $data['old'] ?? Session::pullOldInput();

        return View::render($template, $data);
    }

    protected function redirect(string $path): string
    {
        return Response::redirect($path);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,mixed> $old
     */
    protected function backWithErrors(string $path, array $errors, array $old): string
    {
        Session::setErrors($errors);
        Session::setOldInput($old);

        return $this->redirect($path);
    }

    /**
     * Returns a redirect (with a flash error) when the CSRF token is invalid,
     * or null when the request may proceed.
     */
    protected function rejectInvalidCsrf(Request $request, string $redirectPath): ?string
    {
        if (Csrf::verify($request->post('_csrf'))) {
            return null;
        }

        Session::flash('error', self::CSRF_ERROR);

        return $this->redirect($redirectPath);
    }
}
