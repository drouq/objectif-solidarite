<?php
declare(strict_types=1);

/** Public site: pages, sitemap, robots, 404. Visitors never get a cookie. */
final class PublicController
{
    public static function handle(string $path): void
    {
        if ($path === '/') {
            $home = Pages::home();
            if ($home === null) {
                throw new HttpStop(404);
            }
            self::showPage($home);
            return;
        }
        if ($path === '/sitemap.xml') {
            self::sitemap();
            return;
        }
        if ($path === '/robots.txt') {
            self::robots();
            return;
        }
        if ($path === '/__rewrite-check') {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
            echo 'rewrite-ok';
            return;
        }
        if (starts_with($path, '/media/')) {
            self::media(substr($path, 7));
            return;
        }
        // Remove a trailing slash: /page/ -> /page
        if (strlen($path) > 1 && substr($path, -1) === '/') {
            Http::redirect(rtrim($path, '/'), 301);
        }
        $slug = substr($path, 1);
        if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
            // Try the normalised form (capitals, accents) before giving up.
            $normal = Slug::make($slug);
            if ($normal !== '' && $normal !== $slug && strpos($slug, '/') === false
                && (Pages::findBySlug($normal) !== null || Pages::redirectTarget($normal) !== null)) {
                Http::redirect('/' . $normal, 301);
            }
            throw new HttpStop(404);
        }
        $page = Pages::findBySlug($slug);
        if ($page === null) {
            $target = Pages::redirectTarget($slug);
            if ($target !== null) {
                Http::redirect(Pages::path($target), 301);
            }
            throw new HttpStop(404);
        }
        if ((int) $page['is_home'] === 1) {
            Http::redirect('/', 301);
        }
        self::showPage($page);
    }

    private static function showPage(array $page): void
    {
        $preview = false;
        if ($page['status'] !== 'published') {
            // Hidden pages: 404 for visitors, preview for signed-in editors.
            $userId = Session::peekUserId();
            if ($userId === null || Users::find($userId) === null) {
                throw new HttpStop(404);
            }
            $preview = true;
        }
        $ancestors = Pages::ancestors($page);
        foreach ($ancestors as $ancestor) {
            if ($ancestor['status'] !== 'published' && !$preview) {
                throw new HttpStop(404); // a page under a hidden page is hidden too
            }
        }
        $children = (int) $page['is_home'] === 1 ? [] : Pages::children((int) $page['id'], true);
        Renderer::preloadMedia(array_merge([$page], $children));
        $blocksHtml = Renderer::blocks(Pages::blocks($page));

        $settings = Settings::all();
        $description = $page['seo_description'] !== '' ? $page['seo_description'] : Pages::autoDescription($page);
        if ($description === '') {
            $description = $settings['site_description'] !== '' ? $settings['site_description'] : $settings['tagline'];
        }
        if ($description === '') {
            $description = 'Site de l\'association ' . $settings['site_name'] . '. Faire un don ou adhérer en ligne sur HelloAsso.';
        }
        $isHome = (int) $page['is_home'] === 1;
        $title = $page['seo_title'] !== '' ? $page['seo_title']
            : ($isHome ? $settings['site_name'] . ($settings['tagline'] !== '' ? ' — ' . $settings['tagline'] : '')
                : $page['title'] . ' — ' . $settings['site_name']);

        $shareImage = Renderer::media($page['header_media_id'] !== null ? (int) $page['header_media_id'] : null)
            ?? Renderer::media(Settings::mediaId('og_media_id'));

        header('Content-Type: text/html; charset=utf-8');
        header($preview ? 'Cache-Control: no-store' : 'Cache-Control: public, max-age=0, must-revalidate');
        if ($preview) {
            header('X-Robots-Tag: noindex');
        }
        echo View::theme('page', [
            'page' => $page,
            'isHome' => $isHome,
            'preview' => $preview,
            'ancestors' => $ancestors,
            'children' => $children,
            'blocksHtml' => $blocksHtml,
            'meta' => [
                'title' => $title,
                'description' => $description,
                'canonical' => absolute_url(Pages::path($page)),
                'image' => $shareImage,
                'noindex' => $preview,
            ],
        ]);
    }

    public static function notFound(): void
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $settings = Settings::all();
        echo View::theme('404', [
            'meta' => [
                'title' => 'Page introuvable — ' . $settings['site_name'],
                'description' => '',
                'canonical' => '',
                'image' => null,
                'noindex' => true,
            ],
        ]);
    }

    private static function sitemap(): void
    {
        $pages = Db::all("SELECT * FROM pages WHERE status = 'published' ORDER BY is_home DESC, position, id");
        $published = [];
        foreach ($pages as $page) {
            $published[(int) $page['id']] = $page;
        }
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($pages as $page) {
            // Skip pages under a hidden parent.
            $visible = true;
            foreach (Pages::ancestors($page) as $ancestor) {
                if (!isset($published[(int) $ancestor['id']])) {
                    $visible = false;
                }
            }
            if (!$visible) {
                continue;
            }
            echo '  <url><loc>' . e(absolute_url(Pages::path($page))) . '</loc><lastmod>'
                . e(substr((string) $page['updated_at'], 0, 10)) . '</lastmod></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    }

    /**
     * Uploaded files stored outside the web folder (Git deployment layout).
     * When they sit in the web folder, Apache serves them directly and this is not used.
     */
    private static function media(string $name): void
    {
        if (!preg_match('/^[a-f0-9]{10,40}(-\d{1,5})?\.(webp|jpg|png|pdf)$/', $name, $m)) {
            throw new HttpStop(404);
        }
        $file = Media::publicDir() . '/' . $name;
        if (!is_file($file)) {
            throw new HttpStop(404);
        }
        $types = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
        $mtime = (int) filemtime($file);
        $lastModified = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        // Addresses carry ?v=<date>, so a long cache is safe for images.
        header_remove('Content-Security-Policy');
        header('Content-Type: ' . $types[$m[2]]);
        header('Cache-Control: public, max-age=' . ($m[2] === 'pdf' ? 86400 : 31536000));
        header('Last-Modified: ' . $lastModified);
        if ($m[2] === 'pdf') {
            header('Content-Disposition: inline; filename="' . $name . '"');
        }
        $since = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($since !== '' && strtotime($since) >= $mtime) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . filesize($file));
        if (Http::method() !== 'HEAD') {
            readfile($file);
        }
    }

    private static function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\nDisallow: /admin\n\nSitemap: " . absolute_url('/sitemap.xml') . "\n";
    }
}
