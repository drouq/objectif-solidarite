<?php
declare(strict_types=1);

/** Request and response helpers. */
final class Http
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /** Decoded request path, always starting with "/". */
    public static function path(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = rawurldecode($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
    }

    public static function host(): string
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) ? $host : 'localhost';
    }

    public static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }

    /** True when the request comes from this computer (local development). */
    public static function isLocalRequest(): bool
    {
        $hostName = strtolower((string) preg_replace('/:\d+$/', '', self::host()));
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $localHost = in_array($hostName, ['localhost', '127.0.0.1', '[::1]'], true);
        $localAddr = in_array($remote, ['127.0.0.1', '::1'], true);
        return $localHost && $localAddr;
    }

    public static function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** @return mixed */
    public static function post(string $key, $default = '')
    {
        return array_key_exists($key, $_POST) ? $_POST[$key] : $default;
    }

    public static function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    public static function query(string $key): string
    {
        $value = $_GET[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    /** Headers sent with every response produced by PHP. */
    public static function sendSecurityHeaders(bool $isAdmin): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        $csp = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
            . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; "
            . "form-action 'self'; frame-ancestors 'none'; "
            . "frame-src https://www.youtube-nocookie.com https://player.vimeo.com";
        if ($isAdmin) {
            // The rich text editor sets a few inline styles from JavaScript-generated markup.
            $csp = str_replace("style-src 'self'", "style-src 'self' 'unsafe-inline'", $csp);
        }
        header('Content-Security-Policy: ' . $csp);
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function redirect(string $url, int $status = 302): void
    {
        header('Location: ' . $url, true, $status);
        throw new HttpStop($status);
    }

    /** @param mixed $data */
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        throw new HttpStop($status);
    }

    /** True when a POST was too big for PHP: $_POST and $_FILES arrive empty. */
    public static function postTooLarge(): bool
    {
        return self::isPost()
            && empty($_POST)
            && empty($_FILES)
            && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }
}
