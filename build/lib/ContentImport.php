<?php
declare(strict_types=1);

/**
 * Content import: reads the content package (source-content/), checks it, and builds a seed
 * (database + media) ready to upload into the server's os-private/ folder.
 * Runs on a computer only. See build/import-content.php for the command line.
 *
 * Privacy: only the pages and the files they reference go into the seed. The package's
 * working files (inventories, open questions...) are read for checks only, never copied.
 */
final class ContentImport
{
    public const BLOCK_TYPES = ['text', 'card_grid', 'embed'];
    public const REQUIRED_FIELDS = ['title', 'slug', 'parent', 'position', 'show_in_menu', 'seo_title', 'meta_description', 'header_image'];
    public const MAX_TOP_MENU = 7;
    public const MAX_SEO_TITLE = 60;
    public const MAX_META = 155;
    public const KNOWN_PLACEHOLDERS = ['HELLOASSO_DON_URL', 'HELLOASSO_ADHESION_URL', 'HELLOASSO_URL'];
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Words in 05-media.csv that mean "rights or consent not cleared": such files are skipped. */
    public const RIGHTS_FLAGS = '/EXCLURE|ne pas publier|accord|consentement|droits inconnus|à vérifier|enfant|mineur|identifiable|dignit|IBAN|numéro de compte|à retirer|les retirer|non visionnée|tiers/iu';

    /** @var string */
    public $source;
    /** @var array<string,array<string,mixed>> pages by slug, in file order */
    public $pages = [];
    /** @var array<int,array{0:string,1:string,2:string}> [code, where, message] */
    public $failures = [];
    /** @var array<int,array{0:string,1:string}> [where, message] */
    public $warnings = [];
    /** @var array<string,array<string,mixed>> media references by package path */
    public $media = [];
    /** @var array<string,array<string,string>> rows of 05-media.csv by normalised path */
    public $mediaCsv = [];
    /** @var array<int,array<string,mixed>> */
    public $redirects = [];
    /** @var array<int,array<string,mixed>> */
    public $figures = [];
    /** @var string|null */
    public $home;
    /** @var string[] how the package differs from the description in the PRD */
    public $differences = [];
    /** @var bool missing files become warnings (--allow-missing-media): links and images turn into markers */
    public $allowMissingMedia = false;
    /** @var bool */
    private $loaded = false;

    public function __construct(string $source)
    {
        $this->source = rtrim(str_replace('\\', '/', $source), '/');
    }

    // ------------------------------------------------------------------ reading

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        if (!is_dir($this->source . '/pages')) {
            $this->fail('PACKAGE', 'source', 'No pages/ folder in ' . $this->source . '.');
            return;
        }
        if (!is_dir($this->source . '/media')) {
            $this->differences[] = 'There is no media/ folder: every image and PDF the pages reference is missing.';
        }
        $this->loadMediaCsv();
        $files = glob($this->source . '/pages/*.md') ?: [];
        sort($files);
        foreach ($files as $file) {
            $this->loadPage($file);
        }
        $this->findHome();
        $this->loadRedirects();
        $this->loadFigures();
    }

    private function loadPage(string $file): void
    {
        $name = basename($file);
        $text = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file));
        $text = (string) preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (!preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $m)) {
            $this->fail('FRONT-MATTER', $name, 'No front matter between --- lines.');
            return;
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv)) {
                $this->fail('FRONT-MATTER', $name, 'Unreadable front matter line: ' . self::short($line));
                continue;
            }
            $value = trim($kv[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $meta[$kv[1]] = $value;
        }
        $slug = $meta['slug'] ?? '';
        $page = [
            'file' => $name,
            'meta' => $meta,
            'title' => $meta['title'] ?? '',
            'slug' => $slug,
            'parent' => isset($meta['parent']) && !in_array(strtolower($meta['parent']), ['', 'none', 'null', '~'], true) ? $meta['parent'] : null,
            'position' => $meta['position'] ?? '',
            'show_in_menu' => $meta['show_in_menu'] ?? '',
            'seo_title' => $meta['seo_title'] ?? '',
            'meta_description' => $meta['meta_description'] ?? '',
            'header_image' => $meta['header_image'] ?? '',
            'blocks' => $this->parseBlocks($m[2], $name),
            'body' => $m[2],
        ];
        $page['markers'] = Markers::inText(implode("\n", [$page['title'], $page['seo_title'], $page['meta_description'], $page['header_image'], $page['body']]));
        $key = $slug !== '' ? $slug : '#' . $name;
        if (isset($this->pages[$key])) {
            $this->fail('SLUG-DUPLICATE', $name, 'Slug "' . $slug . '" is already used by ' . $this->pages[$key]['file'] . '.');
            return;
        }
        $this->pages[$key] = $page;
    }

    /** @return array<int,array<string,mixed>> */
    private function parseBlocks(string $body, string $file): array
    {
        $parts = preg_split('/^<!--\s*block:\s*([a-z_]+)\s*-->[ \t]*$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $blocks = [];
        if (trim((string) $parts[0]) !== '') {
            $this->warn($file, 'Text before the first block marker: imported as a text block.');
            $blocks[] = ['type' => 'text', 'content' => (string) $parts[0]];
        }
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $type = $parts[$i];
            $content = (string) $parts[$i + 1];
            $block = ['type' => $type, 'content' => $content];
            if ($type === 'card_grid') {
                $block['cards'] = $this->parseCards($content, $file);
            } elseif ($type === 'embed') {
                $block['url'] = preg_match('/^\s*url:\s*(.+?)\s*$/m', $content, $u) ? $u[1] : '';
            }
            $blocks[] = $block;
        }
        return $blocks;
    }

    /** @return array<int,array<string,string>> */
    private function parseCards(string $content, string $file): array
    {
        $cards = [];
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^-\s+([a-z_]+):\s*(.*)$/', $line, $m)) {
                $cards[] = [$m[1] => trim($m[2])];
            } elseif (preg_match('/^\s+([a-z_]+):\s*(.*)$/', $line, $m) && $cards) {
                $cards[count($cards) - 1][$m[1]] = trim($m[2]);
            } else {
                $this->fail('CARD-FORMAT', $file, 'Unreadable card line: ' . self::short($line));
            }
        }
        foreach ($cards as $card) {
            foreach (array_keys($card) as $field) {
                if (!in_array($field, ['title', 'text', 'image', 'link'], true)) {
                    $this->warn($file, 'Unknown card field "' . $field . '" ignored.');
                }
            }
        }
        return $cards;
    }

    private function loadMediaCsv(): void
    {
        $file = $this->source . '/05-media.csv';
        if (!is_file($file)) {
            $this->warn('05-media.csv', 'Missing: no alt texts and no rights information. Every image is skipped.');
            return;
        }
        foreach (self::readCsv($file) as $row) {
            if (isset($row['fichier'])) {
                $this->mediaCsv[self::normPath($row['fichier'])] = $row;
            }
        }
    }

    private function findHome(): void
    {
        $file = $this->source . '/03-architecture.md';
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $candidates = [];
        // "- **Accueil** `accueil` : ..." in the page tree
        if (preg_match('/^\s*[-*]\s+\*\*Accueil\*\*\s+`([a-z0-9-]+)`/mu', $text, $m)) {
            $candidates[] = $m[1];
        }
        // "| Accueil | `/` | `/` (accueil) |" in the old-address table
        if (preg_match('/\|\s*Accueil\s*\|\s*`\/`\s*\|\s*`\/`\s*\(([a-z0-9-]+)\)/u', $text, $m)) {
            $candidates[] = $m[1];
        }
        $candidates = array_values(array_unique($candidates));
        if (count($candidates) !== 1) {
            $this->fail('HOME', '03-architecture.md', count($candidates) === 0
                ? 'The home page is not clear from 03-architecture.md. Ask Daniel which page is the home page.'
                : '03-architecture.md names several home pages (' . implode(', ', $candidates) . '). Ask Daniel.');
            return;
        }
        if (!isset($this->pages[$candidates[0]])) {
            $this->fail('HOME', '03-architecture.md', 'The home page "' . $candidates[0] . '" has no page file.');
            return;
        }
        $this->home = $candidates[0];
    }

    private function loadRedirects(): void
    {
        $file = $this->source . '/07-redirect-seeds.csv';
        if (!is_file($file)) {
            $this->warn('07-redirect-seeds.csv', 'Missing: no redirects from the old site.');
            return;
        }
        $seen = [];
        foreach (self::readCsv($file) as $n => $row) {
            $old = trim((string) ($row['ancien_chemin'] ?? ''));
            $new = trim((string) ($row['nouveau_chemin'] ?? ''));
            $where = '07-redirect-seeds.csv line ' . ($n + 2);
            if ($old === '' || $old[0] !== '/') {
                $this->fail('REDIRECT', $where, 'Old path must start with /: ' . self::short($old));
                continue;
            }
            $key = OldPaths::normalize($old);
            $entry = ['old' => $key, 'new' => $new, 'note' => (string) ($row['note'] ?? ''), 'action' => '', 'page' => null, 'url' => ''];
            if (isset($seen[$key])) {
                continue; // the same address in its encoded and decoded forms
            }
            $seen[$key] = true;
            if ($key === '/contact-8') {
                // PRD section 7: the old donation target goes to HelloAsso (it was a redirect to HelloAsso on Wix).
                $entry['action'] = 'helloasso';
                $entry['url'] = OldPaths::HELLOASSO_TARGET;
                if ($new !== '' && !preg_match('~^https?://~', $new)) {
                    $this->differences[] = 'The package sends /contact-8 to ' . $new . '; the PRD (section 7) says HelloAsso. The PRD wins: /contact-8 goes to the HelloAsso page set in Réglages (and to /nous-aider while that is empty).';
                }
            } elseif ($new === '' || preg_match('/^(removed|supprimée?|none|410|404)$/iu', $new)) {
                $entry['action'] = 'gone';
            } elseif (preg_match('~^https?://~i', $new)) {
                $entry['action'] = 'url';
                $entry['url'] = $new;
            } elseif ($new[0] === '/') {
                $slug = trim(OldPaths::normalize($new), '/');
                if ($slug === '') {
                    $slug = (string) $this->home;
                }
                if (OldPaths::normalize($new) === $key || '/' . $slug === $key || ($key === '/' && $slug === $this->home)) {
                    $entry['action'] = 'same';
                } elseif (!isset($this->pages[$slug])) {
                    $this->fail('REDIRECT', $where, 'Target page ' . $new . ' does not exist in the package.');
                    continue;
                } else {
                    $entry['action'] = 'page';
                    $entry['page'] = $slug;
                }
            } else {
                $this->fail('REDIRECT', $where, 'Unreadable target: ' . self::short($new));
                continue;
            }
            if ($entry['action'] === 'page' && isset($this->pages[ltrim($key, '/')])) {
                $this->warn($where, 'Old path ' . $key . ' is also the address of a new page: the page wins, the redirect is never used.');
            }
            $this->redirects[] = $entry;
        }
    }

    private function loadFigures(): void
    {
        $file = $this->source . '/04-key-figures.md';
        if (!is_file($file)) {
            return;
        }
        $text = (string) file_get_contents($file);
        // The "Réglages" section: a table with the key in backticks and its current value.
        if (!preg_match('/^##\s+R[ée]glages[^\n]*\n(.*?)(?=^##\s|\z)/msu', $text, $section)) {
            $this->warn('04-key-figures.md', 'No "Réglages" section: no key figures to seed.');
            return;
        }
        $position = 0;
        foreach (explode("\n", $section[1]) as $line) {
            if (!preg_match('/^\|\s*`([a-z0-9_]+)`\s*\|\s*([^|]*)\|/u', $line, $m)) {
                continue;
            }
            $raw = trim($m[2]);
            $note = '';
            if (preg_match('/\(([^)]*)\)\s*$/u', $raw, $p)) {
                $note = $p[1];
                $raw = trim(substr($raw, 0, -strlen($p[0])));
            }
            $value = $raw;
            $unit = '';
            $status = 'seeded';
            if (Markers::inText($raw)) {
                $value = '';
                $status = 'left empty (marker)';
            } elseif (preg_match('/^(.*?)\s*(€|%)$/u', $raw, $u)) {
                $value = $u[1];
                $unit = $u[2];
            }
            $this->figures[] = [
                'key' => $m[1], 'label' => self::humanize($m[1]), 'value' => $value, 'unit' => $unit,
                'position' => ++$position, 'note' => $note, 'status' => $status,
            ];
        }
    }

    // --------------------------------------------------------------- validation

    public function validate(): void
    {
        $this->load();
        $reserved = Slug::RESERVED;
        $topMenu = 0;
        foreach ($this->pages as $key => $page) {
            $file = $page['file'];
            foreach (self::REQUIRED_FIELDS as $field) {
                if (!array_key_exists($field, $page['meta'])) {
                    $this->fail('FIELD-MISSING', $file, 'Front matter field "' . $field . '" is missing.');
                }
            }
            if (trim($page['title']) === '') {
                $this->fail('TITLE', $file, 'No title.');
            } elseif (mb_strlen($page['title'], 'UTF-8') > 200) {
                $this->fail('TITLE', $file, 'Title longer than 200 characters.');
            }
            $slug = $page['slug'];
            if ($slug === '') {
                $this->fail('SLUG-MISSING', $file, 'No slug.');
            } elseif (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 80) {
                $this->fail('SLUG-FORMAT', $file, 'Slug "' . $slug . '" must be lowercase letters and digits joined by single hyphens, no accents (80 characters at most).');
            } elseif (in_array($slug, $reserved, true)) {
                $this->fail('SLUG-RESERVED', $file, 'Slug "' . $slug . '" is reserved by the site.');
            }
            if (!preg_match('/^\d{1,4}$/', (string) $page['position'])) {
                $this->fail('POSITION', $file, 'Position "' . $page['position'] . '" is not a number.');
            }
            if (!in_array($page['show_in_menu'], ['true', 'false'], true)) {
                $this->fail('BOOLEAN', $file, 'show_in_menu must be true or false.');
            }
            if ($page['parent'] !== null && !isset($this->pages[$page['parent']])) {
                $this->fail('PARENT-MISSING', $file, 'Parent "' . $page['parent'] . '" does not exist.');
            }
            $depth = $this->depth($key);
            if ($depth === null) {
                $this->fail('PARENT-CYCLE', $file, 'The parents form a loop.');
            } elseif ($depth > Pages::MAX_DEPTH) {
                $this->fail('DEPTH', $file, 'Level ' . $depth . ': the site allows ' . Pages::MAX_DEPTH . ' levels at most.');
            }
            if ($page['parent'] === null && $page['show_in_menu'] === 'true') {
                $topMenu++;
            }
            if ($page['parent'] !== null && $page['show_in_menu'] === 'true') {
                $this->warn($file, 'show_in_menu is true on a sub-page: the menu only shows top-level pages.');
            }
            if (mb_strlen($page['seo_title'], 'UTF-8') > self::MAX_SEO_TITLE) {
                $this->fail('SEO-TITLE-LENGTH', $file, 'seo_title has ' . mb_strlen($page['seo_title'], 'UTF-8') . ' characters (' . self::MAX_SEO_TITLE . ' at most).');
            }
            if (mb_strlen($page['meta_description'], 'UTF-8') > self::MAX_META) {
                $this->fail('META-LENGTH', $file, 'meta_description has ' . mb_strlen($page['meta_description'], 'UTF-8') . ' characters (' . self::MAX_META . ' at most).');
            }
            if ($page['parent'] !== null) {
                if (trim($page['meta_description']) === '' || Markers::inText($page['meta_description'])) {
                    $this->fail('CHILD-DESCRIPTION', $file, 'A sub-page needs a description (meta_description): it is shown on its card.');
                }
                if (self::isNone($page['header_image']) || Markers::inText($page['header_image'])) {
                    // Daniel's decision (7 November 2026): a warning, not a failure. The card shows without a photo.
                    $this->warn($file, 'No header image on this sub-page: its card on the parent page has no photo. Found: "' . self::short($page['header_image']) . '".');
                }
            }
            if (($meta = $page['meta']['status'] ?? 'draft') !== 'draft') {
                $this->warn($file, 'status is "' . $meta . '": ignored, pages are imported as drafts unless --publish.');
            }
            $this->validateBlocks($page);
            $this->collectMedia($page);
        }
        if ($topMenu > self::MAX_TOP_MENU) {
            $this->fail('MENU-COUNT', 'pages', $topMenu . ' top-level menu items: ' . self::MAX_TOP_MENU . ' at most.');
        }
        if ($this->home !== null) {
            $home = $this->pages[$this->home];
            if ($home['parent'] !== null) {
                $this->fail('HOME', $home['file'], 'The home page must be a top-level page.');
            }
            foreach ($this->pages as $page) {
                if ($page['parent'] === $this->home) {
                    $this->fail('HOME', $page['file'], 'The home page cannot have sub-pages.');
                }
            }
        }
        $this->checkMediaFiles();
    }

    private function validateBlocks(array $page): void
    {
        $file = $page['file'];
        if (!$page['blocks']) {
            $this->warn($file, 'No blocks: the page will be empty.');
        }
        foreach ($page['blocks'] as $n => $block) {
            $where = $file . ' block ' . ($n + 1);
            if (!in_array($block['type'], self::BLOCK_TYPES, true)) {
                $this->fail('BLOCK-TYPE', $where, 'Block type "' . $block['type'] . '" is not allowed (text, card_grid, embed).');
                continue;
            }
            if ($block['type'] === 'embed') {
                $url = (string) $block['url'];
                if ($url === '') {
                    $this->fail('EMBED', $where, 'Embed block without "url:".');
                } elseif (Markers::inText($url)) {
                    $this->warn($where, 'Video address still to be given (' . self::short($url) . '): imported as a marker in a text block.');
                } elseif (Embed::parse($url) === null) {
                    $this->fail('EMBED-HOST', $where, 'Only YouTube and Vimeo videos are allowed: ' . self::short($url));
                }
            }
            if ($block['type'] === 'card_grid') {
                foreach ($block['cards'] as $c => $card) {
                    $cardWhere = $where . ' card ' . ($c + 1);
                    if (trim((string) ($card['title'] ?? '')) === '') {
                        $this->fail('CARD-TITLE', $cardWhere, 'A card needs a title.');
                    }
                    $link = trim((string) ($card['link'] ?? ''));
                    if ($link !== '' && !preg_match('~^https?://~', $link) && !Markers::inText($link) && !self::isFileLink($link)
                        && !in_array(trim($link, '{}'), self::KNOWN_PLACEHOLDERS, true) && !isset($this->pages[ltrim($link, '/')])) {
                        $this->fail('CARD-LINK', $cardWhere, 'Card link "' . $link . '" is not a page of the package.');
                    }
                }
            }
            $text = $block['type'] === 'text' ? $block['content'] : json_encode($block, JSON_UNESCAPED_UNICODE);
            if (preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/u', (string) $text, $m)) {
                foreach ($m[1] as $name) {
                    if (in_array($name, self::KNOWN_PLACEHOLDERS, true)) {
                        continue;
                    }
                    if (preg_match('/^fig:([a-z0-9_]+)$/', $name, $f)) {
                        if (!in_array($f[1], array_column($this->figures, 'key'), true)) {
                            $this->fail('PLACEHOLDER', $where, 'Key figure {{' . $name . '}} is not defined in 04-key-figures.md. Ask Daniel.');
                        }
                        continue;
                    }
                    $this->fail('PLACEHOLDER', $where, 'Unknown placeholder {{' . $name . '}}. Ask Daniel what it should become.');
                }
            }
            if ($block['type'] === 'text') {
                foreach ($this->links($block['content']) as $link) {
                    $url = $link[1];
                    if (self::isFileLink($url) || preg_match('~^(https?:|mailto:|tel:|#|\{\{)~', $url)) {
                        continue;
                    }
                    if ($url[0] === '/') {
                        $slug = trim((string) parse_url($url, PHP_URL_PATH), '/');
                        if ($slug !== '' && !isset($this->pages[$slug])) {
                            $this->warn($where, 'Link to ' . $url . ': no such page in the package (broken link).');
                        }
                    } else {
                        $this->fail('LINK', $where, 'Link address "' . self::short($url) . '" is not allowed (use https://, / or mailto:).');
                    }
                }
            }
        }
    }

    /** Every image and PDF the page refers to. */
    private function collectMedia(array $page): void
    {
        if (!self::isNone($page['header_image']) && !Markers::inText($page['header_image'])) {
            $this->addRef($page['header_image'], $page['slug'], 'header image');
        }
        foreach ($page['blocks'] as $block) {
            if ($block['type'] === 'card_grid') {
                foreach ($block['cards'] as $card) {
                    $image = trim((string) ($card['image'] ?? ''));
                    if (!self::isNone($image) && !Markers::inText($image)) {
                        $this->addRef($image, $page['slug'], 'card image');
                    }
                    $link = trim((string) ($card['link'] ?? ''));
                    if ($link !== '' && self::isFileLink($link)) {
                        $this->addRef($link, $page['slug'], 'card link');
                    }
                }
            } elseif ($block['type'] === 'text') {
                foreach ($this->links($block['content']) as $link) {
                    if ($link[0] === '!') {
                        $this->addRef($link[1], $page['slug'], 'image in text');
                    } elseif (self::isFileLink($link[1])) {
                        $this->addRef($link[1], $page['slug'], 'file link');
                    }
                }
            }
        }
    }

    private function addRef(string $ref, string $slug, string $use): void
    {
        $ref = trim($ref);
        if (!isset($this->media[$ref])) {
            $this->media[$ref] = ['ref' => $ref, 'uses' => [], 'status' => '', 'reason' => '', 'file' => null, 'kind' => '', 'alt' => '', 'csv' => null];
        }
        $this->media[$ref]['uses'][] = $slug . ' (' . $use . ')';
    }

    private function checkMediaFiles(): void
    {
        foreach ($this->media as $ref => $item) {
            $csv = $this->mediaCsv[self::normPath($ref)] ?? $this->mediaCsv[self::normPath(ltrim($ref, '/'))] ?? null;
            $item['csv'] = $csv;
            $isPdf = (bool) preg_match('/\.pdf$/i', $ref);
            $item['kind'] = $isPdf ? 'pdf' : 'image';
            $item['alt'] = $csv !== null ? trim((string) ($csv['texte_alternatif_fr'] ?? '')) : '';
            $rights = $csv !== null ? trim((string) ($csv['droits_ou_consentement'] ?? '') . ' ' . (string) ($csv['page_suggeree'] ?? '')) : '';
            $rightsCheck = (string) preg_replace("/pas d['’]IBAN/iu", '', $rights);
            if ($csv !== null && preg_match(self::RIGHTS_FLAGS, $rightsCheck)) {
                $item['status'] = 'skipped';
                $item['reason'] = 'rights or consent: ' . self::short($rights, 160);
            } elseif ($csv === null && !$isPdf) {
                $item['status'] = 'skipped';
                $item['reason'] = 'not described in 05-media.csv (no alt text, rights unknown)';
            } else {
                $file = $this->resolveFile($ref);
                if ($file === null) {
                    $item['status'] = 'missing';
                    $item['reason'] = 'file not found in media/';
                    if ($this->allowMissingMedia) {
                        $item['reason'] .= ': allowed by --allow-missing-media, the link becomes a marker';
                        $this->warn($ref, 'Missing file allowed (--allow-missing-media): used by ' . implode(', ', $item['uses']) . ', shown as [À COMPLÉTER : document non importé].');
                    } else {
                        $this->fail('MEDIA-MISSING', $ref, 'Referenced file not found in media/ (used by ' . implode(', ', $item['uses']) . ').');
                    }
                } else {
                    $item['file'] = $file;
                    $mime = Media::detectMime($file);
                    $size = (int) filesize($file);
                    $allowed = $isPdf ? ['application/pdf'] : self::IMAGE_TYPES;
                    if (!in_array($mime, $allowed, true)) {
                        $item['status'] = 'bad type';
                        $item['reason'] = 'real type ' . $mime;
                        $this->fail('MEDIA-TYPE', $ref, 'File type ' . $mime . ' is not allowed (JPG, PNG, WebP, PDF).');
                    } elseif ($size > Config::maxBytes($isPdf ? 'pdf' : 'image')) {
                        $item['status'] = 'too big';
                        $item['reason'] = Media::formatSize($size);
                        $this->fail('MEDIA-SIZE', $ref, 'File is ' . Media::formatSize($size) . ' (limit ' . Media::formatSize(Config::maxBytes($isPdf ? 'pdf' : 'image')) . ').');
                    } else {
                        $item['status'] = 'ok';
                        if ($csv === null) {
                            $this->warn($ref, 'PDF not described in 05-media.csv: check it holds no personal data before publishing.');
                        }
                        if ($csv !== null && preg_match('/low-resolution|pas utilisable/i', (string) ($csv['qualite'] ?? ''))) {
                            $this->warn($ref, 'Marked low resolution in 05-media.csv: ' . self::short((string) $csv['qualite']));
                        }
                    }
                }
            }
            $this->media[$ref] = $item;
        }
    }

    /** Where a reference is on disk: media/<ref>, or for "/files/x.pdf" also media/files/x.pdf and media/x.pdf. */
    public function resolveFile(string $ref): ?string
    {
        $clean = ltrim(str_replace('\\', '/', $ref), '/');
        if ($clean === '' || strpos($clean, '..') !== false) {
            return null;
        }
        $candidates = [$this->source . '/media/' . $clean];
        if (strpos($clean, 'files/') === 0) {
            $candidates[] = $this->source . '/media/' . substr($clean, 6);
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        // The package may lay files out flat (media/photos/, media/pdf/): accept a file with the
        // same name anywhere under media/, only if there is exactly one.
        $matches = $this->findByName(basename($clean));
        if (count($matches) === 1) {
            if (!isset($this->foundByName[$ref])) {
                $this->foundByName[$ref] = substr($matches[0], strlen($this->source) + 1);
            }
            return $matches[0];
        }
        if (count($matches) > 1) {
            $this->fail('MEDIA-AMBIGUOUS', $ref, 'Several files named ' . basename($clean) . ' under media/: '
                . implode(', ', array_map(function (string $m): string {
                    return substr($m, strlen($this->source) + 1);
                }, $matches)) . '.');
        }
        return null;
    }

    /** @var array<string,string> reference => file found by its name only (for the report) */
    public $foundByName = [];

    /** @var array<string,string[]>|null lowercase file name => paths under media/ */
    private $mediaIndex;

    /** @return string[] */
    private function findByName(string $name): array
    {
        if ($this->mediaIndex === null) {
            $this->mediaIndex = [];
            $root = $this->source . '/media';
            if (is_dir($root)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $this->mediaIndex[strtolower($file->getFilename())][] = str_replace('\\', '/', $file->getPathname());
                    }
                }
            }
        }
        return $this->mediaIndex[strtolower($name)] ?? [];
    }

    private function depth(string $key): ?int
    {
        $depth = 1;
        $seen = [$key => true];
        $parent = $this->pages[$key]['parent'];
        while ($parent !== null) {
            if (isset($seen[$parent])) {
                return null;
            }
            $seen[$parent] = true;
            if (!isset($this->pages[$parent])) {
                return $depth + 1;
            }
            $depth++;
            $parent = $this->pages[$parent]['parent'];
        }
        return $depth;
    }

    /** @return array<int,array{0:string,1:string,2:string}> [!, url, label] of Markdown links and images */
    private function links(string $markdown): array
    {
        $out = [];
        if (preg_match_all('/(!?)\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*([^()\s]+)(?:\s+"[^"]*")?\s*\)/u', $markdown, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $out[] = [$match[1], $match[3], $match[2]];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- results

    /** @return array<int,array{0:string,1:string,2:string}> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function willPublish(array $page, bool $publish): bool
    {
        return $page['slug'] === $this->home || ($publish && count($page['markers']) === 0);
    }

    public function report(string $mode, bool $publish): string
    {
        $pages = $this->pages;
        $levels = [1 => 0, 2 => 0, 3 => 0];
        $blockCounts = ['text' => 0, 'card_grid' => 0, 'embed' => 0];
        $markerTotal = 0;
        $withMarkers = 0;
        foreach ($pages as $key => $page) {
            $d = $this->depth($key);
            if ($d !== null && isset($levels[$d])) {
                $levels[$d]++;
            }
            foreach ($page['blocks'] as $block) {
                $blockCounts[$block['type']] = ($blockCounts[$block['type']] ?? 0) + 1;
            }
            $markerTotal += count($page['markers']);
            $withMarkers += $page['markers'] ? 1 : 0;
        }
        $mediaBy = ['ok' => 0, 'skipped' => 0, 'missing' => 0, 'other' => 0];
        $kinds = ['image' => 0, 'pdf' => 0];
        foreach ($this->media as $item) {
            $kinds[$item['kind']] = ($kinds[$item['kind']] ?? 0) + 1;
            $mediaBy[isset($mediaBy[$item['status']]) ? $item['status'] : 'other']++;
        }
        $L = [];
        $L[] = '# Content import report';
        $L[] = '';
        $L[] = '- Mode: `' . $mode . '`' . ($publish ? ' with `--publish`' : '') . ', ' . gmdate('Y-m-d H:i') . ' UTC';
        $L[] = '- PHP ' . PHP_VERSION . ', SQLite ' . self::sqliteVersion();
        $L[] = '- Package: `' . $this->source . '`';
        $L[] = '- Result: ' . ($this->failures ? '**' . count($this->failures) . ' validation failure(s). Nothing can be imported until they are fixed.**' : '**all checks pass.**');
        $L[] = '';
        $L[] = '## Summary';
        $L[] = '';
        $L[] = '| | Count |';
        $L[] = '|---|---|';
        $L[] = '| Pages | ' . count($pages) . ' (level 1: ' . $levels[1] . ', level 2: ' . $levels[2] . ', level 3: ' . $levels[3] . ') |';
        $L[] = '| Home page | ' . ($this->home !== null ? '`' . $this->home . '`' : 'not found') . ' |';
        $L[] = '| Blocks | ' . array_sum($blockCounts) . ' (text ' . $blockCounts['text'] . ', card grid ' . $blockCounts['card_grid'] . ', embed ' . $blockCounts['embed'] . ') |';
        $L[] = '| Media referenced | ' . count($this->media) . ' (images ' . $kinds['image'] . ', PDFs ' . $kinds['pdf'] . ') |';
        $L[] = '| Media to import | ' . $mediaBy['ok'] . ' |';
        $L[] = '| Media skipped (rights, consent, not described) | ' . $mediaBy['skipped'] . ' |';
        $L[] = '| Media missing or refused | ' . ($mediaBy['missing'] + $mediaBy['other']) . ' |';
        $L[] = '| Markers `[À VÉRIFIER` / `[À COMPLÉTER` | ' . $markerTotal . ' in ' . $withMarkers . ' pages |';
        $L[] = '| Pages that would be published | ' . count(array_filter($pages, function ($p) use ($publish) {
            return $this->willPublish($p, $publish);
        })) . ($publish ? '' : ' (only the home page: without `--publish` every other page is a draft)') . ' |';
        $L[] = '| Old addresses (301) | ' . count(array_filter($this->redirects, function ($r) {
            return $r['action'] !== 'same';
        })) . ' |';
        $L[] = '| Key figures | ' . count($this->figures) . ' |';
        $L[] = '';
        $L[] = '## Validation failures';
        $L[] = '';
        if (!$this->failures) {
            $L[] = 'None.';
        } else {
            $byCode = [];
            foreach ($this->failures as $f) {
                $byCode[$f[0]][] = $f;
            }
            foreach ($byCode as $code => $list) {
                $L[] = '### ' . $code . ' (' . count($list) . ')';
                $L[] = '';
                foreach ($list as $f) {
                    $L[] = '- `' . $f[1] . '`: ' . $f[2];
                }
                $L[] = '';
            }
        }
        $L[] = '';
        $L[] = '## Warnings';
        $L[] = '';
        if (!$this->warnings) {
            $L[] = 'None.';
        }
        foreach ($this->warnings as $w) {
            $L[] = '- `' . $w[0] . '`: ' . $w[1];
        }
        $L[] = '';
        $L[] = '## Pages';
        $L[] = '';
        $L[] = '| File | Slug | Parent | Level | Blocks | Markers | Published |';
        $L[] = '|---|---|---|---|---|---|---|';
        foreach ($pages as $key => $page) {
            $L[] = '| ' . $page['file'] . ' | ' . $page['slug'] . ' | ' . ($page['parent'] ?? '—') . ' | ' . ($this->depth($key) ?? '?')
                . ' | ' . count($page['blocks']) . ' | ' . count($page['markers']) . ' | ' . ($this->willPublish($page, $publish) ? 'yes' . ($page['slug'] === $this->home ? ' (home page, always published)' : '') : 'no') . ' |';
        }
        $L[] = '';
        $L[] = '## Media';
        $L[] = '';
        if (!$this->media) {
            $L[] = 'No media referenced.';
        } else {
            $L[] = '| Reference | Kind | Used by | Status | Reason |';
            $L[] = '|---|---|---|---|---|';
            foreach ($this->media as $item) {
                $L[] = '| ' . self::cell($item['ref']) . ' | ' . $item['kind'] . ' | ' . self::cell(implode(', ', array_unique($item['uses']))) . ' | ' . $item['status'] . ' | ' . self::cell($item['reason']) . ' |';
            }
        }
        $L[] = '';
        $L[] = '## Old addresses';
        $L[] = '';
        $L[] = '| Old path | Goes to | Note |';
        $L[] = '|---|---|---|';
        foreach ($this->redirects as $r) {
            $to = $r['action'] === 'page' ? '/' . $r['page'] : ($r['action'] === 'helloasso' ? 'HelloAsso page (Réglages)' : ($r['action'] === 'url' ? $r['url'] : ($r['action'] === 'gone' ? 'removed (404)' : 'same address: nothing to do')));
            $L[] = '| ' . self::cell($r['old']) . ' | ' . self::cell($to) . ' | ' . self::cell(self::short($r['note'], 90)) . ' |';
        }
        $L[] = '';
        $L[] = '## Key figures (Réglages > Chiffres clés)';
        $L[] = '';
        $L[] = '| Key | Value | Unit | Status | Note |';
        $L[] = '|---|---|---|---|---|';
        foreach ($this->figures as $f) {
            $L[] = '| ' . $f['key'] . ' | ' . self::cell($f['value']) . ' | ' . $f['unit'] . ' | ' . $f['status'] . ' | ' . self::cell($f['note']) . ' |';
        }
        $L[] = '';
        $L[] = '## Package compared with the PRD';
        $L[] = '';
        if ($this->foundByName) {
            $this->differences[] = 'Files are not at the paths the pages use: ' . count($this->foundByName)
                . ' were found by their file name only (unique match under media/). Check the list below.';
        }
        if (!$this->differences) {
            $L[] = 'No difference detected automatically.';
        }
        foreach (array_unique($this->differences) as $d) {
            $L[] = '- ' . $d;
        }
        if ($this->foundByName) {
            $L[] = '';
            $L[] = '### Files found by name';
            $L[] = '';
            $L[] = '| Reference in the pages | File used |';
            $L[] = '|---|---|';
            foreach ($this->foundByName as $ref => $file) {
                $L[] = '| ' . self::cell($ref) . ' | ' . self::cell($file) . ' |';
            }
        }
        return implode("\n", $L) . "\n";
    }

    // ------------------------------------------------------------------ import

    /**
     * Build the database and media. The app must be loaded with DATA_PATH and media_path
     * pointing into the seed folder. Returns the number of pages, media and redirects created.
     * @return array<string,int>
     */
    public function import(bool $publish): array
    {
        if ($this->failures) {
            throw new RuntimeException('Validation failed: nothing imported.');
        }
        Migrations::run();
        Db::pdo()->exec('PRAGMA journal_mode = DELETE');
        $stats = ['pages' => 0, 'media' => 0, 'redirects' => 0, 'figures' => 0];
        Db::transaction(function () use ($publish, &$stats): void {
            // Media first: pages refer to them by id.
            $byRef = [];
            foreach ($this->media as $ref => $item) {
                if ($item['status'] !== 'ok') {
                    continue;
                }
                $row = Media::store((string) $item['file'], basename(str_replace('\\', '/', $ref)), 'import');
                if ($item['alt'] !== '') {
                    Media::setAlt((int) $row['id'], $item['alt']);
                }
                $byRef[$ref] = Media::find((int) $row['id']);
                $stats['media']++;
            }
            // Pages, parents before children.
            $ids = [];
            $remaining = $this->pages;
            while ($remaining) {
                $progress = false;
                foreach ($remaining as $key => $page) {
                    if ($page['parent'] !== null && !isset($ids[$page['parent']])) {
                        continue;
                    }
                    $ids[$key] = $this->importPage($page, $page['parent'] !== null ? $ids[$page['parent']] : null, $byRef, $publish);
                    unset($remaining[$key]);
                    $progress = true;
                    $stats['pages']++;
                }
                if (!$progress) {
                    throw new RuntimeException('Page tree could not be ordered.');
                }
            }
            foreach (array_unique(array_merge([null], array_values($ids))) as $parentId) {
                Pages::normalizePositions($parentId);
            }
            foreach ($ids as $id) {
                Pages::updateMediaUsage($id);
            }
            foreach ($this->redirects as $r) {
                if ($r['action'] === 'same') {
                    continue;
                }
                OldPaths::put($r['old'], $r['action'] === 'page' ? $ids[$r['page']] : null,
                    in_array($r['action'], ['url', 'helloasso'], true) ? $r['url'] : '', $r['note']);
                $stats['redirects']++;
            }
            foreach ($this->figures as $f) {
                KeyFigures::put($f['key'], $f['label'], $f['value'], $f['unit'], (int) $f['position']);
                $stats['figures']++;
            }
        });
        $check = (string) Db::value('PRAGMA integrity_check');
        if ($check !== 'ok') {
            throw new RuntimeException('SQLite integrity check failed: ' . $check);
        }
        return $stats;
    }

    /** @param array<string,array<string,mixed>> $byRef */
    private function importPage(array $page, ?int $parentId, array $byRef, bool $publish): int
    {
        $blocks = [];
        foreach ($page['blocks'] as $block) {
            if ($block['type'] === 'text') {
                $blocks[] = ['type' => 'text', 'html' => $this->textHtml($block['content'], $byRef)];
            } elseif ($block['type'] === 'card_grid') {
                $cards = [];
                foreach ($block['cards'] as $card) {
                    $image = trim((string) ($card['image'] ?? ''));
                    $text = trim((string) ($card['text'] ?? ''));
                    $mediaId = null;
                    if (Markers::inText($image)) {
                        $text = trim($text . ' ' . $image); // keep the marker visible to editors
                    } elseif (!self::isNone($image)) {
                        if (isset($byRef[$image])) {
                            $mediaId = (int) $byRef[$image]['id'];
                        } else {
                            $text = trim($text . ' [À COMPLÉTER : photo non importée]');
                        }
                    }
                    $link = trim((string) ($card['link'] ?? ''));
                    if (self::isFileLink($link)) {
                        // A card that opens a library file (a PDF).
                        if (isset($byRef[$link])) {
                            $link = Media::url($byRef[$link]);
                            $link = (string) preg_replace('/\?v=\d+$/', '', $link);
                        } else {
                            $text = trim($text . ' [À COMPLÉTER : document non importé]');
                            $link = '';
                        }
                    } else {
                        $link = $this->pageLink($link);
                    }
                    $cards[] = ['media_id' => $mediaId, 'title' => trim((string) ($card['title'] ?? '')), 'text' => $text, 'url' => $link];
                }
                $blocks[] = ['type' => 'cards', 'cards' => $cards];
            } elseif ($block['type'] === 'embed') {
                $url = (string) $block['url'];
                $blocks[] = Markers::inText($url)
                    ? ['type' => 'text', 'html' => '<p>' . e($url) . '</p>']
                    : ['type' => 'embed', 'url' => $url];
            }
        }
        list($clean, $errors) = Pages::cleanBlocks((string) json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($errors) {
            throw new RuntimeException($page['file'] . ': ' . implode(' ', $errors));
        }
        $isHome = $page['slug'] === $this->home;
        $header = $page['header_image'];
        $now = now_iso();
        $data = [
            'parent_id' => $parentId,
            'position' => (int) $page['position'],
            'title' => $page['title'],
            'slug' => $page['slug'],
            'status' => $this->willPublish($page, $publish) ? 'published' : 'hidden',
            'show_in_menu' => $page['show_in_menu'] === 'true' && $parentId === null && !$isHome ? 1 : 0,
            'show_in_footer' => 0,
            'is_home' => $isHome ? 1 : 0,
            'header_media_id' => isset($byRef[$header]) ? (int) $byRef[$header]['id'] : null,
            'seo_title' => $page['seo_title'],
            'seo_description' => $page['meta_description'],
            'blocks' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
            'updated_by' => 'import',
        ];
        $existingHome = $isHome ? Pages::home() : null;
        if ($existingHome !== null) {
            // The installation's placeholder home page becomes the imported one.
            Db::update('pages', (int) $existingHome['id'], $data);
            Db::run('DELETE FROM page_versions WHERE page_id = ?', [(int) $existingHome['id']]);
            $id = (int) $existingHome['id'];
        } else {
            $data['created_at'] = $now;
            $id = Db::insert('pages', $data);
        }
        Pages::recordVersion($id, 'import', 'Import du contenu');
        return $id;
    }

    /** @param array<string,array<string,mixed>> $byRef */
    private function textHtml(string $markdown, array $byRef): string
    {
        // Links to files that are not imported keep their text and get a marker.
        $markdown = (string) preg_replace_callback('/(?<!!)\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*([^()\s]+)\s*\)/u', function (array $m) use ($byRef): string {
            if (self::isFileLink($m[2]) && !isset($byRef[$m[2]])) {
                return $m[1] . ' [À COMPLÉTER : document non importé]';
            }
            return $m[0];
        }, $markdown);
        $converter = new Markdown(function (string $url) use ($byRef): ?string {
            if (isset($byRef[$url])) {
                return '/media/' . $byRef[$url]['token'] . '.pdf';
            }
            return $url[0] === '/' && !self::isFileLink($url) ? $this->pageLink(ltrim($url, '/')) : $url;
        }, function (string $file, string $alt) use ($byRef): string {
            if (!isset($byRef[$file]) || $byRef[$file]['kind'] !== 'image') {
                return ' [À COMPLÉTER : image non importée] ';
            }
            $media = $byRef[$file];
            return '<img src="' . e(Media::url($media)) . '" data-media-id="' . (int) $media['id'] . '" alt="' . e($alt !== '' ? $alt : (string) $media['alt']) . '">';
        });
        return Sanitizer::html($converter->toHtml($markdown));
    }

    /** A card or text link to a page slug ("transparence", "/transparence#x") becomes the page's address. */
    private function pageLink(string $link): string
    {
        if ($link === '' || preg_match('~^(https?:|mailto:|tel:|#|\{\{)~', $link)) {
            return $link;
        }
        $path = ltrim($link, '/');
        $fragment = '';
        if (($hash = strpos($path, '#')) !== false) {
            $fragment = substr($path, $hash);
            $path = substr($path, 0, $hash);
        }
        if ($path === $this->home) {
            return '/' . $fragment;
        }
        return '/' . $path . $fragment;
    }

    // ----------------------------------------------------------------- helpers

    private function fail(string $code, string $where, string $message): void
    {
        $this->failures[] = [$code, $where, $message];
    }

    private function warn(string $where, string $message): void
    {
        $this->warnings[] = [$where, $message];
    }

    public static function isNone(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['', 'none', 'null', '~'], true);
    }

    public static function isFileLink(string $url): bool
    {
        return !preg_match('~^(https?:|mailto:|tel:|\{\{)~i', $url)
            && (strpos(ltrim($url, '/'), 'files/') === 0 || preg_match('/\.(pdf|jpe?g|png|webp)$/i', $url));
    }

    private static function normPath(string $path): string
    {
        return strtolower(trim(str_replace('\\', '/', $path)));
    }

    /** @return array<int,array<string,string>> */
    public static function readCsv(string $file): array
    {
        $rows = [];
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        $header = null;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($header === null) {
                $row[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                $header = array_map('trim', $row);
                continue;
            }
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), '')) ?: [];
        }
        fclose($handle);
        return $rows;
    }

    private static function humanize(string $key): string
    {
        $text = str_replace('_', ' ', $key);
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
    }

    private static function short(string $text, int $max = 100): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max - 1, 'UTF-8') . '…' : $text;
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    public static function sqliteVersion(): string
    {
        try {
            return (string) (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
        } catch (Throwable $ex) {
            return 'unavailable';
        }
    }
}
