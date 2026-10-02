<?php

declare(strict_types=1);

/**
 * Test doubles for the PHP built-ins that App\Core\Response / App\Core\Csv /
 * App\Core\Session call. PHP resolves unqualified function calls inside a
 * namespace to the namespaced function first, so defining them here (no app
 * code change) lets tests observe the status code and headers a controller
 * emitted - the CLI SAPI otherwise records neither (headers_list() is always
 * empty) and warns "headers already sent" once PHPUnit has printed output.
 */

namespace Tests\Support {
    final class ResponseCapture
    {
        public static int $status = 200;

        /** @var array<string,string> lower-cased name => value */
        public static array $headers = [];

        public static function reset(): void
        {
            self::$status = 200;
            self::$headers = [];
        }
    }
}

namespace App\Core {
    use Tests\Support\ResponseCapture;

    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
        $name = strtolower(trim($name));
        ResponseCapture::$headers[$name] = trim($value);

        // What the real header() does: a Location header implies 302.
        if ($name === 'location' && ResponseCapture::$status === 200) {
            ResponseCapture::$status = 302;
        }
        if ($response_code > 0) {
            ResponseCapture::$status = $response_code;
        }
    }

    function http_response_code(int $response_code = 0): int
    {
        if ($response_code > 0) {
            ResponseCapture::$status = $response_code;
        }

        return ResponseCapture::$status;
    }

    function session_regenerate_id(bool $delete_old_session = false): bool
    {
        // No real session exists in CLI tests; $_SESSION is a plain array.
        return true;
    }
}
