<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\Response;

final class ErrorController extends Controller
{
    public function forbidden(): string
    {
        Response::status(403);

        return $this->view('errors.403', ['title' => 'Akses Ditolak']);
    }

    public function notFound(): string
    {
        Response::status(404);

        return $this->view('errors.404', ['title' => 'Tidak Ditemukan']);
    }

    public function serverError(): string
    {
        Response::status(500);

        return $this->view('errors.500', ['title' => 'Terjadi Kesalahan']);
    }
}
