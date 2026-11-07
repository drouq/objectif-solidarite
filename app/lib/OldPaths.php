<?php
declare(strict_types=1);

/**
 * Addresses of the old websites (Wix, SPIP) and where they go now. Permanent (301) redirects.
 *
 * An old path is stored decoded ("/mafé", not "/maf%C3%A9") and may hold a query string
 * ("/spip.php?article7"). It points to a page, to an outside address, or nowhere
 * (removed content: the visitor gets the friendly 404 page).
 * The target "{{HELLOASSO_URL}}" means the association's HelloAsso page from Réglages.
 */
final class OldPaths
{
    public const HELLOASSO_TARGET = '{{HELLOASSO_URL}}';

    /** "/maf%C3%A9" and "/mafé" give the same key. */
    public static function normalize(string $path): string
    {
        $query = '';
        $q = strpos($path, '?');
        if ($q !== false) {
            $query = substr($path, $q + 1);
            $path = substr($path, 0, $q);
        }
        $path = rawurldecode($path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $query !== '' ? $path . '?' . rawurldecode($query) : $path;
    }

    /** @return array<string,mixed>|null the matching old path, with the query string first */
    public static function find(string $decodedPath, string $query): ?array
    {
        $candidates = [];
        if ($query !== '') {
            $candidates[] = self::normalize($decodedPath . '?' . $query);
        }
        $candidates[] = self::normalize($decodedPath);
        foreach ($candidates as $candidate) {
            $row = Db::one('SELECT * FROM old_paths WHERE old_path = ?', [$candidate]);
            if ($row !== null) {
                return $row;
            }
        }
        return null;
    }

    /** Where an old path goes now, or null when its content was removed. */
    public static function target(array $row): ?string
    {
        if ($row['page_id'] !== null) {
            $page = Pages::find((int) $row['page_id']);
            return $page !== null ? Pages::path($page) : null;
        }
        $url = (string) $row['target_url'];
        if ($url === self::HELLOASSO_TARGET) {
            return Settings::helloAssoUrl();
        }
        return $url !== '' && Settings::isHttpUrl($url) ? $url : null;
    }

    /** Add or replace an old path. */
    public static function put(string $oldPath, ?int $pageId, string $targetUrl, string $note = ''): void
    {
        $key = self::normalize($oldPath);
        Db::run('DELETE FROM old_paths WHERE old_path = ?', [$key]);
        Db::insert('old_paths', [
            'old_path' => $key,
            'page_id' => $pageId,
            'target_url' => $pageId !== null ? '' : $targetUrl,
            'note' => mb_substr($note, 0, 300, 'UTF-8'),
            'created_at' => now_iso(),
        ]);
    }
}
