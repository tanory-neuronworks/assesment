<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $path): string
    {
        header('Location: ' . $path);

        return '';
    }

    public static function json(array $data, int $status = 200): string
    {
        http_response_code($status);
        header('Content-Type: application/json');

        return (string) json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    public static function status(int $status): void
    {
        http_response_code($status);
    }
}
