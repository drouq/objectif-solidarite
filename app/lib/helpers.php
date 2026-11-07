<?php
declare(strict_types=1);

/** Escape for HTML text and attributes. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Absolute URL for a site path ("/contact" → "https://site/contact"). */
function absolute_url(string $path): string
{
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return Config::baseUrl() . '/' . ltrim($path, '/');
}

/** URL of a static asset with a cache-busting version. */
function asset(string $path): string
{
    $file = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : APP_VERSION;
    return '/assets/' . ltrim($path, '/') . '?v=' . $version;
}

function starts_with(string $haystack, string $needle): bool
{
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
}

function ends_with(string $haystack, string $needle): bool
{
    return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
}

/** Current time as stored in the database (UTC, ISO 8601). */
function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** Format a stored UTC date for French readers (Paris time). */
function format_date_fr(?string $iso, bool $withTime = true): string
{
    if ($iso === null || $iso === '') {
        return '—';
    }
    try {
        $date = new DateTime($iso, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Europe/Paris'));
    } catch (Exception $ex) {
        return $iso;
    }
    return $date->format($withTime ? 'd/m/Y à H:i' : 'd/m/Y');
}

/** Cut a plain text to a maximum length on a word boundary. */
function excerpt(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text, 'UTF-8') <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max, 'UTF-8');
    $space = mb_strrpos($cut, ' ', 0, 'UTF-8');
    if ($space !== false && $space > $max * 0.6) {
        $cut = mb_substr($cut, 0, $space, 'UTF-8');
    }
    return rtrim($cut, " ,;:.-") . '…';
}

/** Hidden CSRF field for admin forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(Session::csrfToken()) . '">';
}

/** Error message under a form field, if any. @param array<string,string> $errors */
function field_error(array $errors, string $field): string
{
    if (!isset($errors[$field])) {
        return '';
    }
    return '<p class="field-error" id="err-' . e($field) . '">' . e($errors[$field]) . '</p>';
}

/** Create a folder (and parents) if it does not exist. */
function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier : ' . $dir);
    }
}

/** Raised for problems the user can fix (shown to them in French). */
final class UserError extends RuntimeException
{
}

/** Raised to stop and send an HTTP response (redirect, 404...). */
final class HttpStop extends RuntimeException
{
    /** @var int */
    public $status;

    public function __construct(int $status)
    {
        parent::__construct('HTTP ' . $status);
        $this->status = $status;
    }
}
