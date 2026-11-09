<?php
declare(strict_types=1);

/** Site settings from config.php (secrets, paths, limits). */
final class Config
{
    /** @var array<string,mixed> */
    private static $values = [];

    /** @var bool */
    private static $loaded = false;

    private const DEFAULTS = [
        'env' => 'production',
        'base_url' => '',
        'force_https' => true,
        'data_path' => null,
        'media_path' => null,
        'admin_email' => '',
        'google_client_id' => '',
        'google_client_secret' => '',
        'dev_login' => false,
        'max_image_mb' => 10,
        'max_pdf_mb' => 20,
        'max_video_mb' => 50,
        'session_idle_hours' => 8,
        'check_enabled' => true,
    ];

    public static function load(string $file): void
    {
        $values = [];
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $values = $loaded;
            }
            self::$loaded = true;
        }
        self::$values = array_merge(self::DEFAULTS, $values);
    }

    /** For tests. @param array<string,mixed> $values */
    public static function override(array $values): void
    {
        self::$values = array_merge(self::$values ?: self::DEFAULTS, $values);
        self::$loaded = true;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /** @return mixed */
    public static function get(string $key)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : null;
    }

    public static function isProduction(): bool
    {
        return self::get('env') !== 'development';
    }

    /** @var string folder where config.php was found */
    private static $configDir = '';

    /**
     * Where config.php is. Looked for in this order:
     * 1. next to app/ (FileZilla install, local development);
     * 2. in a sibling folder named "<folder>-private" (Git deployment: e.g. os/ and os-private/),
     *    so that a Git deployment can never overwrite or delete it.
     */
    public static function locate(string $rootPath): string
    {
        $candidates = [
            $rootPath . '/config.php',
            dirname($rootPath) . '/' . basename($rootPath) . '-private/config.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                self::$configDir = str_replace('\\', '/', dirname($file));
                return $file;
            }
        }
        self::$configDir = str_replace('\\', '/', $rootPath);
        return $candidates[0];
    }

    /** True when config.php sits outside the program folder (Git deployment layout). */
    public static function isPrivateLayout(): bool
    {
        return self::$configDir !== '' && self::$configDir !== str_replace('\\', '/', ROOT_PATH);
    }

    /** Database, originals, sessions, logs. Default: "data" next to config.php. */
    public static function dataPath(): string
    {
        $path = self::get('data_path');
        if (!is_string($path) || $path === '') {
            $path = (self::$configDir !== '' ? self::$configDir : ROOT_PATH) . '/data';
        }
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * Uploaded files (web versions and PDFs).
     * Default: the web folder's "media" (served by Apache), or "media" next to config.php
     * in the Git layout (served by the site itself, out of Git's reach).
     */
    public static function mediaPath(): string
    {
        $path = self::get('media_path');
        if (!is_string($path) || $path === '') {
            $path = self::isPrivateLayout() ? self::$configDir . '/media' : PUBLIC_PATH . '/media';
        }
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    public static function baseUrl(): string
    {
        $url = (string) self::get('base_url');
        if ($url === '') {
            $url = (Http::isHttps() ? 'https://' : 'http://') . Http::host();
        }
        return rtrim($url, '/');
    }

    /**
     * A test site (base_url on a "test." address) must stay out of search engines:
     * its pages may still hold internal questions. Go-live changes base_url, which lifts this.
     */
    public static function isTestSite(): bool
    {
        $host = strtolower((string) parse_url((string) self::get('base_url'), PHP_URL_HOST));
        return starts_with($host, 'test.');
    }

    public static function maxBytes(string $kind): int
    {
        $mb = $kind === 'pdf' ? self::get('max_pdf_mb') : ($kind === 'video' ? self::get('max_video_mb') : self::get('max_image_mb'));
        return (int) round(((float) $mb) * 1024 * 1024);
    }
}
