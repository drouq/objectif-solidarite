<?php
declare(strict_types=1);

/** Site settings edited in the "Réglages" screen (stored in the settings table). */
final class Settings
{
    /**
     * The association's main HelloAsso page. Empty until Daniel gives the real address:
     * no address is guessed. While empty, the buttons lead to the "Nous aider" page.
     */
    public const DEFAULT_HELLOASSO = '';

    /** Where the buttons go while no HelloAsso address is set. */
    public const FALLBACK_SUPPORT_PATH = '/nous-aider';

    /** Field definitions: key => [label, type, default]. */
    public const FIELDS = [
        'site_name' => ['Nom du site', 'text', 'Objectif Solidarité'],
        'tagline' => ['Slogan (sous le nom)', 'text', ''],
        'site_description' => ['Description courte (moteurs de recherche)', 'textarea', ''],
        'contact_email' => ['E-mail de contact', 'email', ''],
        'logo_media_id' => ['Logo', 'media', ''],
        'favicon_media_id' => ['Icône du site (favicon, image carrée)', 'media', ''],
        'og_media_id' => ['Image de partage par défaut (réseaux sociaux)', 'media', ''],
        'helloasso_url' => ['Page HelloAsso de l\'association', 'url', self::DEFAULT_HELLOASSO],
        'helloasso_don_url' => ['Lien « Faire un don » (si vide : page HelloAsso)', 'url', ''],
        'helloasso_adhesion_url' => ['Lien « Adhérer » (si vide : page HelloAsso)', 'url', ''],
        'footer_text' => ['Texte du pied de page', 'textarea', ''],
    ];

    /** @var array<string,string>|null */
    private static $cache;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $values = [];
            foreach (self::FIELDS as $key => $field) {
                $values[$key] = (string) $field[2];
            }
            foreach (Db::all('SELECT key, value FROM settings') as $row) {
                $values[(string) $row['key']] = (string) $row['value'];
            }
            self::$cache = $values;
        }
        return self::$cache;
    }

    public static function get(string $key): string
    {
        $all = self::all();
        return $all[$key] ?? '';
    }

    public static function mediaId(string $key): ?int
    {
        $value = (int) self::get($key);
        return $value > 0 ? $value : null;
    }

    public static function set(string $key, string $value): void
    {
        Db::run(
            // INSERT OR REPLACE works on old SQLite versions too (no UPSERT needed).
            'INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)',
            [$key, $value]
        );
        self::$cache = null;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    public static function donUrl(): string
    {
        $url = self::get('helloasso_don_url');
        return $url !== '' ? $url : self::helloAssoUrl();
    }

    public static function adhesionUrl(): string
    {
        $url = self::get('helloasso_adhesion_url');
        return $url !== '' ? $url : self::helloAssoUrl();
    }

    public static function helloAssoUrl(): string
    {
        $url = self::get('helloasso_url');
        if ($url === '') {
            $url = self::DEFAULT_HELLOASSO;
        }
        return $url !== '' ? $url : self::FALLBACK_SUPPORT_PATH;
    }

    /** True while no HelloAsso address at all is set (the admin shows a warning). */
    public static function helloAssoMissing(): bool
    {
        return self::get('helloasso_url') === '' && self::get('helloasso_don_url') === ''
            && self::get('helloasso_adhesion_url') === '' && self::DEFAULT_HELLOASSO === '';
    }

    /**
     * Validate and save the settings form.
     * @param array<string,mixed> $input
     * @return array<string,string> errors by field
     */
    public static function saveForm(array $input): array
    {
        $errors = [];
        $clean = [];
        foreach (self::FIELDS as $key => $field) {
            $value = isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
            $type = $field[1];
            if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$key] = 'Adresse e-mail invalide.';
            }
            if ($type === 'url' && $value !== '' && !self::isHttpUrl($value)) {
                $errors[$key] = 'Adresse invalide. Elle doit commencer par https://';
            }
            if ($type === 'media' && $value !== '') {
                $media = Media::find((int) $value);
                if ($media === null || $media['kind'] !== 'image') {
                    $errors[$key] = 'Image introuvable.';
                }
                $value = (string) (int) $value;
            }
            if ($key === 'site_name' && $value === '') {
                $errors[$key] = 'Le nom du site est obligatoire.';
            }
            $max = $type === 'textarea' ? 1000 : 300;
            if (mb_strlen($value, 'UTF-8') > $max) {
                $errors[$key] = 'Texte trop long (' . $max . ' caractères maximum).';
            }
            $clean[$key] = $value;
        }
        if ($errors) {
            return $errors;
        }
        Db::transaction(function () use ($clean): void {
            foreach ($clean as $key => $value) {
                self::set($key, $value);
            }
            $ids = [];
            foreach (['logo_media_id', 'favicon_media_id', 'og_media_id'] as $key) {
                if ($clean[$key] !== '') {
                    $ids[] = (int) $clean[$key];
                }
            }
            Media::setUsage('settings', 0, $ids);
        });
        return [];
    }

    public static function isHttpUrl(string $url): bool
    {
        return (bool) preg_match('~^https?://[^\s/$.?#][^\s"<>]*$~i', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
