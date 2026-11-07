<?php
declare(strict_types=1);

/**
 * Key figures that change every year (funds collected, running costs...).
 * Edited in Réglages > Chiffres clés. Texts show them with {{fig:<key>}}, replaced when the page is shown.
 */
final class KeyFigures
{
    public const KEY_PATTERN = '/^[a-z0-9_]{1,60}$/';
    public const PLACEHOLDER = '/\{\{fig:([a-z0-9_]{1,60})\}\}/';

    /** @var array<string,array<string,mixed>>|null */
    private static $cache;

    /** @var array<string,bool> keys already reported as unknown during this request */
    private static $logged = [];

    /** @return array<int,array<string,mixed>> ordered for display */
    public static function all(): array
    {
        return Db::all('SELECT * FROM key_figures ORDER BY position, id');
    }

    /** @return array<string,array<string,mixed>> by key */
    public static function map(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (self::all() as $row) {
                self::$cache[(string) $row['fig_key']] = $row;
            }
        }
        return self::$cache;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /** @return array<int,array<string,mixed>> figures shown in the home page band (with a value) */
    public static function onHome(): array
    {
        return array_values(array_filter(self::all(), function (array $row): bool {
            return (int) $row['on_home'] === 1 && trim((string) $row['value']) !== '';
        }));
    }

    /** Value and unit, as shown to visitors (not escaped). */
    public static function display(array $row): string
    {
        $value = trim((string) $row['value']);
        $unit = trim((string) $row['unit']);
        if ($value === '') {
            return '';
        }
        // A non-breaking space keeps "12 270,96 €" on one line.
        return $unit === '' ? $value : $value . "\u{00A0}" . $unit;
    }

    /** Replace {{fig:key}} in already escaped HTML or text. Unknown keys show nothing and are logged. */
    public static function replaceIn(string $escaped): string
    {
        if (strpos($escaped, '{{fig:') === false) {
            return $escaped;
        }
        $map = self::map();
        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $m) use ($map): string {
            if (!isset($map[$m[1]])) {
                self::logUnknown($m[1]);
                return '';
            }
            return e(self::display($map[$m[1]]));
        }, $escaped);
    }

    private static function logUnknown(string $key): void
    {
        if (isset(self::$logged[$key])) {
            return;
        }
        self::$logged[$key] = true;
        $dir = DATA_PATH . '/logs';
        if (is_dir($dir)) {
            @file_put_contents($dir . '/content.log', '[' . gmdate('c') . '] Chiffre clé inconnu : {{fig:' . $key . '}} sur '
                . Http::path() . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Save the "Chiffres clés" table of the settings form.
     * Each row: key, label, value, unit, on_home, delete. A row with an empty key is ignored.
     * @param mixed $rows
     * @return array<string,string> errors (key "fig")
     */
    public static function saveForm($rows): array
    {
        $rows = is_array($rows) ? $rows : [];
        $clean = [];
        $errors = [];
        $seen = [];
        $position = 0;
        foreach (array_slice($rows, 0, 100) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $str = function (string $name) use ($row): string {
                return isset($row[$name]) && is_string($row[$name]) ? trim($row[$name]) : '';
            };
            $key = $str('key');
            if ($key === '' || $str('delete') === '1') {
                continue;
            }
            if (!preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = 'Clé « ' . $key . ' » invalide : minuscules sans accents, chiffres et _ seulement.';
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = 'La clé « ' . $key . ' » est utilisée deux fois.';
                continue;
            }
            $seen[$key] = true;
            foreach (['label' => 120, 'value' => 120, 'unit' => 20] as $field => $max) {
                if (mb_strlen($str($field), 'UTF-8') > $max) {
                    $errors[] = 'Chiffre « ' . $key . ' » : texte trop long (' . $max . ' caractères maximum).';
                }
            }
            $clean[] = [
                'fig_key' => $key,
                'label' => $str('label'),
                'value' => $str('value'),
                'unit' => $str('unit'),
                'on_home' => $str('on_home') === '1' ? 1 : 0,
                'position' => ++$position,
            ];
        }
        if ($errors) {
            return ['fig' => implode(' ', $errors)];
        }
        Db::transaction(function () use ($clean): void {
            Db::run('DELETE FROM key_figures');
            foreach ($clean as $row) {
                Db::insert('key_figures', $row);
            }
        });
        self::clearCache();
        return [];
    }

    /** Add or update one figure (content import). */
    public static function put(string $key, string $label, string $value, string $unit, int $position, bool $onHome = false): void
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException('Invalid figure key: ' . $key);
        }
        Db::run('DELETE FROM key_figures WHERE fig_key = ?', [$key]);
        Db::insert('key_figures', [
            'fig_key' => $key, 'label' => $label, 'value' => $value, 'unit' => $unit,
            'position' => $position, 'on_home' => $onHome ? 1 : 0,
        ]);
        self::clearCache();
    }
}
