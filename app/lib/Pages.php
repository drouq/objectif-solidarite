<?php
declare(strict_types=1);

/** Pages: tree, saving, address changes (301 redirects) and version history. */
final class Pages
{
    public const MAX_DEPTH = 3;
    public const MAX_VERSIONS = 20;

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return Db::one('SELECT * FROM pages WHERE slug = ?', [$slug]);
    }

    /** @return array<string,mixed>|null */
    public static function home(): ?array
    {
        return Db::one('SELECT * FROM pages WHERE is_home = 1 ORDER BY id LIMIT 1');
    }

    /** @return array<int,array<string,mixed>> */
    public static function allPages(): array
    {
        return Db::all('SELECT * FROM pages ORDER BY position, id');
    }

    /** Public address of a page. */
    public static function path(array $page): string
    {
        return (int) $page['is_home'] === 1 ? '/' : '/' . $page['slug'];
    }

    /** @return array<int,array<string,mixed>> */
    public static function children(int $parentId, bool $publishedOnly): array
    {
        $sql = 'SELECT * FROM pages WHERE parent_id = ?' . ($publishedOnly ? " AND status = 'published'" : '')
            . ' ORDER BY position, id';
        return Db::all($sql, [$parentId]);
    }

    /** @return array<int,array<string,mixed>> top-level pages for the main menu */
    public static function menu(): array
    {
        return Db::all("SELECT * FROM pages WHERE parent_id IS NULL AND status = 'published'
            AND show_in_menu = 1 ORDER BY position, id");
    }

    /** @return array<int,array<string,mixed>> */
    public static function footerPages(): array
    {
        return Db::all("SELECT * FROM pages WHERE status = 'published' AND show_in_footer = 1 ORDER BY position, id");
    }

    /**
     * Parents of a page, from the top level down (the page itself not included).
     * @return array<int,array<string,mixed>>
     */
    public static function ancestors(array $page): array
    {
        $chain = [];
        $parentId = $page['parent_id'];
        $guard = 0;
        while ($parentId !== null && $guard++ < 10) {
            $parent = self::find((int) $parentId);
            if ($parent === null) {
                break;
            }
            array_unshift($chain, $parent);
            $parentId = $parent['parent_id'];
        }
        return $chain;
    }

    public static function depth(array $page): int
    {
        return count(self::ancestors($page)) + 1;
    }

    /**
     * The whole tree, flattened in display order, with a "depth" key.
     * @return array<int,array<string,mixed>>
     */
    public static function tree(): array
    {
        $byParent = [];
        foreach (self::allPages() as $page) {
            $key = $page['parent_id'] === null ? 0 : (int) $page['parent_id'];
            $byParent[$key][] = $page;
        }
        $out = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent): void {
            $siblings = $byParent[$parentId] ?? [];
            $count = count($siblings);
            foreach ($siblings as $index => $page) {
                $page['depth'] = $depth;
                $page['is_first'] = $index === 0;
                $page['is_last'] = $index === $count - 1;
                $page['child_count'] = count($byParent[(int) $page['id']] ?? []);
                $out[] = $page;
                $walk((int) $page['id'], $depth + 1);
            }
        };
        $walk(0, 1);
        return $out;
    }

    /** Height of the subtree under a page (1 = no children). */
    public static function subtreeHeight(int $pageId): int
    {
        $max = 0;
        foreach (self::children($pageId, false) as $child) {
            $max = max($max, self::subtreeHeight((int) $child['id']));
        }
        return 1 + $max;
    }

    /** @return int[] ids of all pages under a page */
    public static function descendantIds(int $pageId): array
    {
        $ids = [];
        foreach (self::children($pageId, false) as $child) {
            $ids[] = (int) $child['id'];
            $ids = array_merge($ids, self::descendantIds((int) $child['id']));
        }
        return $ids;
    }

    /**
     * Pages that can be chosen as parent for a page (null = new page).
     * @return array<int,array<string,mixed>>
     */
    public static function parentOptions(?int $pageId): array
    {
        $height = $pageId !== null ? self::subtreeHeight($pageId) : 1;
        $excluded = $pageId !== null ? array_merge([$pageId], self::descendantIds($pageId)) : [];
        $options = [];
        foreach (self::tree() as $page) {
            if (in_array((int) $page['id'], $excluded, true) || (int) $page['is_home'] === 1) {
                continue;
            }
            if ($page['depth'] + $height <= self::MAX_DEPTH) {
                $options[] = $page;
            }
        }
        return $options;
    }

    /** A free address based on a wished slug. */
    public static function uniqueSlug(string $wished, ?int $exceptId = null): string
    {
        $base = $wished !== '' ? $wished : 'page';
        if (Slug::isReserved($base)) {
            $base .= '-page';
        }
        $slug = $base;
        $n = 2;
        while (self::slugTaken($slug, $exceptId)) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    public static function slugTaken(string $slug, ?int $exceptId): bool
    {
        $row = Db::one('SELECT id FROM pages WHERE slug = ?', [$slug]);
        return $row !== null && (int) $row['id'] !== $exceptId;
    }

    /** Create a page with a title and optional parent. Returns its id. */
    public static function create(string $title, ?int $parentId, string $userEmail): int
    {
        $title = trim($title);
        if ($title === '') {
            throw new UserError('Le titre est obligatoire.');
        }
        if (mb_strlen($title, 'UTF-8') > 200) {
            throw new UserError('Titre trop long (200 caractères maximum).');
        }
        if ($parentId !== null) {
            $parent = self::find($parentId);
            if ($parent === null) {
                throw new UserError('Page parente introuvable.');
            }
            if ((int) $parent['is_home'] === 1) {
                throw new UserError('La page d\'accueil ne peut pas avoir de sous-pages.');
            }
            if (self::depth($parent) >= self::MAX_DEPTH) {
                throw new UserError('Trois niveaux de pages au maximum.');
            }
        }
        return (int) Db::transaction(function () use ($title, $parentId, $userEmail): int {
            $position = (int) Db::value(
                'SELECT COALESCE(MAX(position), 0) + 1 FROM pages WHERE parent_id IS ?',
                [$parentId]
            );
            $now = now_iso();
            $id = Db::insert('pages', [
                'parent_id' => $parentId,
                'position' => $position,
                'title' => $title,
                'slug' => self::uniqueSlug(Slug::make($title)),
                'status' => 'hidden',
                'show_in_menu' => $parentId === null ? 1 : 0,
                'show_in_footer' => 0,
                'is_home' => 0,
                'seo_title' => '',
                'seo_description' => '',
                'blocks' => json_encode([['type' => 'text', 'html' => '']]),
                'created_at' => $now,
                'updated_at' => $now,
                'updated_by' => $userEmail,
            ]);
            self::recordVersion($id, $userEmail, 'Création');
            return $id;
        });
    }

    /**
     * Validate the editor form. Returns [clean values, errors].
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    public static function validate(array $page, array $input): array
    {
        $errors = [];
        $id = (int) $page['id'];
        $str = function (string $key) use ($input): string {
            return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
        };

        $title = $str('title');
        if ($title === '') {
            $errors['title'] = 'Le titre est obligatoire.';
        } elseif (mb_strlen($title, 'UTF-8') > 200) {
            $errors['title'] = 'Titre trop long (200 caractères maximum).';
        }

        $slugInput = $str('slug');
        $slug = Slug::make($slugInput !== '' ? $slugInput : $title);
        if ($slug === '') {
            $errors['slug'] = 'Adresse invalide.';
        } elseif (Slug::isReserved($slug)) {
            $errors['slug'] = 'Cette adresse est réservée par le site. Choisissez-en une autre.';
        } elseif (self::slugTaken($slug, $id)) {
            $errors['slug'] = 'Cette adresse est déjà utilisée par une autre page.';
        }

        $isHome = (int) $page['is_home'] === 1 || $str('make_home') === '1';
        if ($isHome && (int) $page['is_home'] !== 1 && self::children($id, false)) {
            $errors['make_home'] = 'Une page qui a des sous-pages ne peut pas devenir la page d\'accueil.';
        }

        $parentId = $str('parent_id') !== '' ? (int) $str('parent_id') : null;
        if ($isHome) {
            $parentId = null;
        }
        if ($parentId !== null && $parentId !== (int) ($page['parent_id'] ?? 0)) {
            $allowed = array_map(function ($p) {
                return (int) $p['id'];
            }, self::parentOptions($id));
            if (!in_array($parentId, $allowed, true)) {
                $errors['parent_id'] = 'Cette page parente n\'est pas possible (trois niveaux au maximum).';
            }
        }

        $status = $str('status') === 'published' ? 'published' : 'hidden';
        if ($isHome && $status !== 'published') {
            $errors['status'] = 'La page d\'accueil doit rester publiée.';
        }

        $seoTitle = $str('seo_title');
        if (mb_strlen($seoTitle, 'UTF-8') > 120) {
            $errors['seo_title'] = 'Titre pour les moteurs de recherche trop long (120 caractères maximum).';
        }
        $seoDescription = $str('seo_description');
        if (mb_strlen($seoDescription, 'UTF-8') > 300) {
            $errors['seo_description'] = 'Description trop longue (300 caractères maximum).';
        }

        $headerId = (int) $str('header_media_id');
        if ($headerId > 0) {
            $media = Media::find($headerId);
            if ($media === null || $media['kind'] !== 'image') {
                $errors['header_media_id'] = 'Image d\'en-tête introuvable.';
            }
        }

        // No block data (editor script did not load): keep the saved content untouched.
        $blocksJson = $str('blocks_json');
        if ($blocksJson === '') {
            $blocksJson = (string) $page['blocks'];
        }
        list($blocks, $blockErrors) = self::cleanBlocks($blocksJson);
        if ($blockErrors) {
            $errors['blocks'] = implode(' ', $blockErrors);
        }

        $clean = [
            'title' => $title,
            'slug' => $slug,
            'parent_id' => $parentId,
            'status' => $status,
            'show_in_menu' => $str('show_in_menu') === '1' ? 1 : 0,
            'show_in_footer' => $str('show_in_footer') === '1' ? 1 : 0,
            'is_home' => $isHome ? 1 : 0,
            'header_media_id' => $headerId > 0 ? $headerId : null,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'blocks' => $blocks,
        ];
        return [$clean, $errors];
    }

    /**
     * Check and clean the blocks sent by the editor.
     * @return array{0:array<int,array<string,mixed>>,1:string[]}
     */
    public static function cleanBlocks(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [[], $json === '' ? [] : ['Le contenu de la page est illisible. Rechargez la page.']];
        }
        $blocks = [];
        $errors = [];
        foreach (array_slice($data, 0, 100) as $index => $block) {
            if (!is_array($block) || !isset($block['type'])) {
                continue;
            }
            $number = $index + 1;
            if ($block['type'] === 'text') {
                $html = Sanitizer::html(is_string($block['html'] ?? null) ? $block['html'] : '');
                $blocks[] = ['type' => 'text', 'html' => $html];
            } elseif ($block['type'] === 'cards') {
                $cards = [];
                foreach (array_slice(is_array($block['cards'] ?? null) ? $block['cards'] : [], 0, 60) as $card) {
                    if (!is_array($card)) {
                        continue;
                    }
                    $title = mb_substr(trim((string) ($card['title'] ?? '')), 0, 200, 'UTF-8');
                    $text = mb_substr(trim((string) ($card['text'] ?? '')), 0, 600, 'UTF-8');
                    $url = trim((string) ($card['url'] ?? ''));
                    $mediaId = (int) ($card['media_id'] ?? 0);
                    if ($title === '' && $text === '' && $mediaId === 0) {
                        continue; // empty card
                    }
                    if ($title === '') {
                        $errors[] = 'Bloc ' . $number . ' : chaque carte doit avoir un titre.';
                    }
                    if ($url !== '') {
                        $safe = Sanitizer::safeHref($url);
                        if ($safe === null) {
                            $errors[] = 'Bloc ' . $number . ' : le lien de la carte « ' . $title
                                . ' » est invalide (il doit commencer par https:// ou /).';
                        }
                        $url = (string) $safe;
                    }
                    $cards[] = [
                        'media_id' => $mediaId > 0 ? $mediaId : null,
                        'title' => $title,
                        'text' => $text,
                        'url' => $url,
                    ];
                }
                $blocks[] = ['type' => 'cards', 'cards' => $cards];
            } elseif ($block['type'] === 'embed') {
                $url = trim((string) ($block['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if (Embed::parse($url) === null) {
                    $errors[] = 'Bloc ' . $number . ' : seules les vidéos YouTube et Vimeo sont acceptées.';
                }
                $blocks[] = ['type' => 'embed', 'url' => mb_substr($url, 0, 500, 'UTF-8')];
            }
        }
        return [$blocks, $errors];
    }

    /** Save the editor form. Returns errors (empty on success). @param array<string,mixed> $input */
    public static function save(int $id, array $input, string $userEmail): array
    {
        $page = self::find($id);
        if ($page === null) {
            throw new HttpStop(404);
        }
        // Someone else saved this page since the editor was opened.
        $loadedAt = isset($input['loaded_updated_at']) ? (string) $input['loaded_updated_at'] : '';
        if ($loadedAt !== '' && $loadedAt !== $page['updated_at']) {
            return ['conflict' => 'Cette page a été modifiée par ' . ($page['updated_by'] ?: 'quelqu\'un')
                . ' pendant que vous la modifiiez (' . format_date_fr((string) $page['updated_at'])
                . '). Vos changements ne sont pas enregistrés. Copiez votre texte, rechargez la page, puis recommencez.'];
        }
        list($clean, $errors) = self::validate($page, $input);
        if ($errors) {
            return $errors;
        }
        Db::transaction(function () use ($page, $clean, $userEmail): void {
            $id = (int) $page['id'];
            if ($clean['slug'] !== $page['slug']) {
                // The old address now sends visitors to this page.
                Db::run('DELETE FROM redirects WHERE old_slug = ?', [$clean['slug']]);
                Db::run('INSERT OR REPLACE INTO redirects (old_slug, page_id, created_at) VALUES (?, ?, ?)',
                    [$page['slug'], $id, now_iso()]);
            }
            if ($clean['is_home'] === 1 && (int) $page['is_home'] !== 1) {
                Db::run('UPDATE pages SET is_home = 0 WHERE is_home = 1');
            }
            $parentChanged = $clean['parent_id'] !== ($page['parent_id'] === null ? null : (int) $page['parent_id']);
            $data = $clean;
            $data['blocks'] = json_encode($clean['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $data['updated_at'] = self::nextTimestamp((string) $page['updated_at']);
            $data['updated_by'] = $userEmail;
            if ($parentChanged) {
                $data['position'] = (int) Db::value(
                    'SELECT COALESCE(MAX(position), 0) + 1 FROM pages WHERE parent_id IS ?',
                    [$clean['parent_id']]
                );
            }
            Db::update('pages', $id, $data);
            if ($parentChanged) {
                self::normalizePositions($page['parent_id'] === null ? null : (int) $page['parent_id']);
            }
            self::updateMediaUsage($id);
            self::recordVersion($id, $userEmail, 'Enregistrement');
        });
        return [];
    }

    /** A new "updated_at" that always differs from the previous one. */
    private static function nextTimestamp(string $previous): string
    {
        $now = now_iso();
        if ($now === $previous) {
            $now = gmdate('Y-m-d\TH:i:s\Z', time() + 1);
        }
        return $now;
    }

    /** @return int[] media ids used by a page */
    public static function mediaIds(array $page): array
    {
        $ids = [];
        if (!empty($page['header_media_id'])) {
            $ids[] = (int) $page['header_media_id'];
        }
        $blocks = is_array($page['blocks']) ? $page['blocks'] : json_decode((string) $page['blocks'], true);
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                if (preg_match_all('/data-media-id="(\d+)"/', (string) $block['html'], $m)) {
                    $ids = array_merge($ids, array_map('intval', $m[1]));
                }
                // Links to library PDFs: /media/<token>.pdf
                if (preg_match_all('~/media/([a-f0-9]{10,40})\.pdf~', (string) $block['html'], $m)) {
                    foreach (array_unique($m[1]) as $token) {
                        $pdfId = Db::value("SELECT id FROM media WHERE token = ? AND kind = 'pdf'", [$token]);
                        if ($pdfId !== null) {
                            $ids[] = (int) $pdfId;
                        }
                    }
                }
            } elseif (($block['type'] ?? '') === 'cards') {
                foreach ($block['cards'] as $card) {
                    if (!empty($card['media_id'])) {
                        $ids[] = (int) $card['media_id'];
                    }
                }
            }
        }
        return array_values(array_unique($ids));
    }

    public static function updateMediaUsage(int $id): void
    {
        $page = self::find($id);
        if ($page !== null) {
            Media::setUsage('page', $id, self::mediaIds($page));
        }
    }

    /** Fields saved in each version. */
    private const VERSION_FIELDS = [
        'title', 'slug', 'status', 'parent_id', 'show_in_menu', 'show_in_footer',
        'header_media_id', 'seo_title', 'seo_description', 'blocks',
    ];

    /** Fields put back when a version is restored (not the address or the place in the tree). */
    private const RESTORE_FIELDS = ['title', 'header_media_id', 'seo_title', 'seo_description', 'blocks'];

    public static function recordVersion(int $pageId, string $userEmail, string $note): void
    {
        $page = self::find($pageId);
        if ($page === null) {
            return;
        }
        $snapshot = [];
        foreach (self::VERSION_FIELDS as $field) {
            $snapshot[$field] = $page[$field];
        }
        Db::insert('page_versions', [
            'page_id' => $pageId,
            'data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now_iso(),
            'user_email' => $userEmail,
            'note' => $note,
        ]);
        Db::run(
            'DELETE FROM page_versions WHERE page_id = ? AND id NOT IN (
                SELECT id FROM page_versions WHERE page_id = ? ORDER BY id DESC LIMIT ' . self::MAX_VERSIONS . ')',
            [$pageId, $pageId]
        );
    }

    /** @return array<int,array<string,mixed>> newest first */
    public static function versions(int $pageId): array
    {
        return Db::all('SELECT * FROM page_versions WHERE page_id = ? ORDER BY id DESC', [$pageId]);
    }

    /** Restore the content of an old version. This creates a new version: nothing is lost. */
    public static function restoreVersion(int $pageId, int $versionId, string $userEmail): void
    {
        $version = Db::one('SELECT * FROM page_versions WHERE id = ? AND page_id = ?', [$versionId, $pageId]);
        if ($version === null) {
            throw new UserError('Version introuvable.');
        }
        $snapshot = json_decode((string) $version['data'], true);
        if (!is_array($snapshot)) {
            throw new UserError('Version illisible.');
        }
        Db::transaction(function () use ($pageId, $snapshot, $userEmail, $version): void {
            $page = self::find($pageId);
            if ($page === null) {
                throw new HttpStop(404);
            }
            $data = [];
            foreach (self::RESTORE_FIELDS as $field) {
                if (array_key_exists($field, $snapshot)) {
                    $data[$field] = $snapshot[$field];
                }
            }
            if (!empty($data['header_media_id']) && Media::find((int) $data['header_media_id']) === null) {
                $data['header_media_id'] = null;
            }
            $data['updated_at'] = self::nextTimestamp((string) $page['updated_at']);
            $data['updated_by'] = $userEmail;
            Db::update('pages', $pageId, $data);
            self::updateMediaUsage($pageId);
            self::recordVersion($pageId, $userEmail, 'Restauration de la version du ' . format_date_fr((string) $version['created_at']));
        });
    }

    /** Move a page up or down among its siblings. */
    public static function move(int $id, string $direction): void
    {
        Db::transaction(function () use ($id, $direction): void {
            $page = self::find($id);
            if ($page === null) {
                return;
            }
            $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
            self::normalizePositions($parentId);
            $siblings = Db::all('SELECT id FROM pages WHERE parent_id IS ? ORDER BY position, id', [$parentId]);
            $ids = array_map(function ($row) {
                return (int) $row['id'];
            }, $siblings);
            $index = array_search($id, $ids, true);
            if ($index === false) {
                return;
            }
            $swap = $direction === 'up' ? $index - 1 : $index + 1;
            if ($swap < 0 || $swap >= count($ids)) {
                return;
            }
            $tmp = $ids[$index];
            $ids[$index] = $ids[$swap];
            $ids[$swap] = $tmp;
            foreach ($ids as $position => $pageId) {
                Db::run('UPDATE pages SET position = ? WHERE id = ?', [$position + 1, $pageId]);
            }
        });
    }

    public static function normalizePositions(?int $parentId): void
    {
        $rows = Db::all('SELECT id FROM pages WHERE parent_id IS ? ORDER BY position, id', [$parentId]);
        foreach ($rows as $position => $row) {
            Db::run('UPDATE pages SET position = ? WHERE id = ?', [$position + 1, $row['id']]);
        }
    }

    public static function setStatus(int $id, string $status, string $userEmail): void
    {
        $page = self::find($id);
        if ($page === null) {
            return;
        }
        if ((int) $page['is_home'] === 1 && $status !== 'published') {
            throw new UserError('La page d\'accueil doit rester publiée.');
        }
        Db::transaction(function () use ($page, $status, $userEmail): void {
            Db::update('pages', (int) $page['id'], [
                'status' => $status === 'published' ? 'published' : 'hidden',
                'updated_at' => self::nextTimestamp((string) $page['updated_at']),
                'updated_by' => $userEmail,
            ]);
            self::recordVersion((int) $page['id'], $userEmail, $status === 'published' ? 'Publication' : 'Masquage');
        });
    }

    public static function delete(int $id): void
    {
        $page = self::find($id);
        if ($page === null) {
            return;
        }
        if ((int) $page['is_home'] === 1) {
            throw new UserError('La page d\'accueil ne peut pas être supprimée. Choisissez d\'abord une autre page d\'accueil.');
        }
        if (self::children($id, false)) {
            throw new UserError('Cette page a des sous-pages. Supprimez-les ou déplacez-les d\'abord.');
        }
        Db::transaction(function () use ($id, $page): void {
            Db::run('DELETE FROM page_versions WHERE page_id = ?', [$id]);
            Db::run('DELETE FROM redirects WHERE page_id = ?', [$id]);
            Db::run("DELETE FROM media_usage WHERE context = 'page' AND ref_id = ?", [$id]);
            Db::run('DELETE FROM pages WHERE id = ?', [$id]);
            self::normalizePositions($page['parent_id'] === null ? null : (int) $page['parent_id']);
        });
    }

    /** @return array<string,mixed>|null page an old address now points to */
    public static function redirectTarget(string $slug): ?array
    {
        $row = Db::one('SELECT page_id FROM redirects WHERE old_slug = ?', [$slug]);
        return $row === null ? null : self::find((int) $row['page_id']);
    }

    /** @return array<int,array<string,mixed>> blocks of a page */
    public static function blocks(array $page): array
    {
        $blocks = json_decode((string) $page['blocks'], true);
        return is_array($blocks) ? $blocks : [];
    }

    /** Text used as meta description when none is given. */
    public static function autoDescription(array $page): string
    {
        foreach (self::blocks($page) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $html = (string) preg_replace('~<h[23][^>]*>.*?</h[23]>~is', ' ', (string) $block['html']);
                $text = Sanitizer::toText($html);
                if ($text !== '' && strpos($text, '[À COMPLÉTER') !== 0) {
                    return excerpt($text, 160);
                }
            }
        }
        return '';
    }
}
