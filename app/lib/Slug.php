<?php
declare(strict_types=1);

/** Web addresses: lowercase, no accents, words joined by hyphens. */
final class Slug
{
    /** Addresses the site already uses for something else. */
    public const RESERVED = [
        'admin', 'assets', 'media', 'data', 'app', 'check', 'check-php', 'index', 'index-php',
        'sitemap-xml', 'robots-txt', 'favicon-ico', 'config', 'config-php', 'well-known',
    ];

    private const MAP = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i',
        'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
        'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', '’' => '-', "'" => '-', '&' => '-et-',
    ];

    public static function make(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, self::MAP);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if (strlen($text) > 80) {
            $text = rtrim(substr($text, 0, 80), '-');
        }
        return $text;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }
}
