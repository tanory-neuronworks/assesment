<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> */
    private array $params = [];

    public function method(): string
    {
        $override = $this->post('_method');
        if ($override !== null && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
            return strtoupper($override);
        }

        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? $default;

        return $value === null ? null : (string) $value;
    }

    public function post(string $key, ?string $default = null): ?string
    {
        $value = $_POST[$key] ?? $default;

        return $value === null ? null : trim((string) $value);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $_POST;
    }

    /**
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function file(string $key): ?array
    {
        if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $_FILES[$key];
    }

    /**
     * Current GET query string with the given keys removed - used to build
     * pagination links that preserve active filters, e.g.
     * "?{$request->queryStringWithout('page')}&page=2".
     */
    public function queryStringWithout(string ...$keys): string
    {
        $params = $_GET;
        foreach ($keys as $key) {
            unset($params[$key]);
        }

        return http_build_query($params);
    }

    /**
     * True when the request was issued via Fetch/XHR (ajax-table.js always
     * sends this header) rather than a normal browser navigation - lets a
     * controller return just the table fragment instead of the full page.
     */
    public function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function setRouteParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }
}
