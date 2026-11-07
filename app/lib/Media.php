<?php
declare(strict_types=1);

/**
 * Media library: images (JPG, PNG, WebP) and PDF files.
 *
 * - Images: the original is kept in data/originals (not public). GD creates web
 *   versions at 480, 960 and 1600 px wide in public/media.
 * - PDFs: stored as-is in public/media.
 * - File names are random. Nothing the user sends is used as a path.
 * - Pages refer to media by id, so replacing a file never breaks a page.
 */
final class Media
{
    public const WIDTHS = [480, 960, 1600];
    private const MAX_PIXELS = 60000000; // 60 megapixels
    private const TYPES = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'application/pdf' => ['pdf', 'pdf'],
    ];

    public static function publicDir(): string
    {
        return Config::mediaPath();
    }

    public static function originalsDir(): string
    {
        return DATA_PATH . '/originals';
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        return Db::one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function allMedia(): array
    {
        return Db::all('SELECT * FROM media ORDER BY id DESC');
    }

    /** @return array<int,array<string,mixed>> media rows by id */
    public static function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $rows = Db::all('SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        return $byId;
    }

    /** @return int[] */
    public static function variants(array $media): array
    {
        $list = json_decode((string) $media['variants'], true);
        return is_array($list) ? array_map('intval', $list) : [];
    }

    /** Public URL of an image at (about) the requested width, or of a PDF. */
    public static function url(array $media, int $width = 960): string
    {
        $version = '?v=' . strtotime((string) $media['updated_at']);
        if ($media['kind'] === 'pdf') {
            return '/media/' . $media['token'] . '.pdf' . $version;
        }
        $variants = self::variants($media);
        $chosen = $variants ? $variants[0] : 0;
        foreach ($variants as $w) {
            $chosen = $w;
            if ($w >= $width) {
                break;
            }
        }
        return '/media/' . $media['token'] . '-' . $chosen . '.' . $media['web_ext'] . $version;
    }

    public static function srcset(array $media): string
    {
        $parts = [];
        $version = '?v=' . strtotime((string) $media['updated_at']);
        foreach (self::variants($media) as $w) {
            $parts[] = '/media/' . $media['token'] . '-' . $w . '.' . $media['web_ext'] . $version . ' ' . $w . 'w';
        }
        return implode(', ', $parts);
    }

    /** Size of the largest web version: [width, height]. */
    public static function displaySize(array $media): array
    {
        $variants = self::variants($media);
        $w = $variants ? max($variants) : (int) $media['width'];
        $h = (int) $media['width'] > 0 ? (int) round($w * (int) $media['height'] / (int) $media['width']) : 0;
        return [$w, $h];
    }

    /**
     * Handle a file from an upload form field.
     * @param array<string,mixed> $file one entry of $_FILES
     * @return array<string,mixed> the media row
     */
    public static function upload(array $file, string $userEmail, ?int $replaceId = null): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UserError('Le fichier est trop lourd pour le serveur.');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UserError('Aucun fichier choisi.');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new UserError('L\'envoi du fichier a échoué. Réessayez.');
        }
        return self::store((string) $file['tmp_name'], (string) ($file['name'] ?? ''), $userEmail, $replaceId);
    }

    /**
     * Check, process and save a file that is already on disk (upload temp file or test file).
     * @return array<string,mixed> the media row
     */
    public static function store(string $path, string $originalName, string $userEmail, ?int $replaceId = null): array
    {
        $size = (int) filesize($path);
        $mime = self::detectMime($path);
        if (!isset(self::TYPES[$mime])) {
            throw new UserError('Type de fichier refusé. Formats acceptés : JPG, PNG, WebP et PDF.');
        }
        list($kind, $originalExt) = self::TYPES[$mime];
        $max = Config::maxBytes($kind);
        if ($size > $max) {
            $label = $kind === 'pdf' ? 'PDF' : 'images';
            throw new UserError('Fichier trop lourd : ' . self::formatSize($size) . '. Limite pour les '
                . $label . ' : ' . self::formatSize($max) . '.');
        }
        $existing = null;
        if ($replaceId !== null) {
            $existing = self::find($replaceId);
            if ($existing === null) {
                throw new UserError('Fichier à remplacer introuvable.');
            }
            if ($existing['kind'] !== $kind) {
                throw new UserError($kind === 'pdf'
                    ? 'Une image ne peut être remplacée que par une image.'
                    : 'Un PDF ne peut être remplacé que par un PDF.');
            }
        }
        $token = $existing !== null ? (string) $existing['token'] : bin2hex(random_bytes(10));
        ensure_dir(self::publicDir());
        ensure_dir(self::originalsDir());

        $width = 0;
        $height = 0;
        $variants = [];
        $webExt = 'pdf';
        if ($kind === 'image') {
            list($width, $height, $variants, $webExt) = self::processImage($path, $mime, $token);
            $originalFile = self::originalsDir() . '/' . $token . '.' . $originalExt;
            if ($existing !== null) {
                self::deleteFiles($existing, $variants, (string) $webExt);
            }
            if (!@copy($path, $originalFile)) {
                throw new RuntimeException('Impossible d\'enregistrer l\'original.');
            }
        } else {
            $head = (string) file_get_contents($path, false, null, 0, 5);
            if ($head !== '%PDF-') {
                throw new UserError('Ce fichier n\'est pas un PDF valide.');
            }
            if (!@copy($path, self::publicDir() . '/' . $token . '.pdf')) {
                throw new RuntimeException('Impossible d\'enregistrer le PDF.');
            }
        }

        $name = mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 200, 'UTF-8');
        $now = now_iso();
        $row = [
            'kind' => $kind,
            'original_ext' => $originalExt,
            'web_ext' => $webExt,
            'original_name' => $name,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'variants' => json_encode($variants),
            'updated_at' => $now,
            'uploaded_by' => $userEmail,
        ];
        if ($existing !== null) {
            if ($existing['updated_at'] === $now) {
                // Keep cache-busting URLs different even for two replacements in the same second.
                $row['updated_at'] = gmdate('Y-m-d\TH:i:s\Z', time() + 1);
            }
            Db::update('media', (int) $existing['id'], $row);
            $id = (int) $existing['id'];
        } else {
            $row['token'] = $token;
            $row['created_at'] = $now;
            $row['alt'] = '';
            $id = Db::insert('media', $row);
        }
        $media = self::find($id);
        if ($media === null) {
            throw new RuntimeException('Media not saved');
        }
        return $media;
    }

    public static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $path);
                if (PHP_VERSION_ID < 80100) {
                    finfo_close($finfo); // no-op and deprecated on recent PHP
                }
                return $mime;
            }
        }
        $info = @getimagesize($path);
        return is_array($info) ? (string) $info['mime'] : '';
    }

    /**
     * Resize an image into web versions.
     * @return array{0:int,1:int,2:int[],3:string} width, height, variant widths, extension
     */
    private static function processImage(string $path, string $mime, string $token): array
    {
        $info = @getimagesize($path);
        if (!is_array($info) || $info['mime'] !== $mime) {
            throw new UserError('Cette image est illisible ou abîmée.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new UserError('Image trop grande (plus de 60 mégapixels).');
        }
        self::raiseMemoryLimit($width * $height);

        if ($mime === 'image/jpeg') {
            $image = @imagecreatefromjpeg($path);
        } elseif ($mime === 'image/png') {
            $image = @imagecreatefrompng($path);
        } else {
            $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        }
        if ($image === false) {
            throw new UserError('Cette image est illisible ou abîmée.');
        }
        if ($mime === 'image/jpeg') {
            $image = self::applyExifOrientation($image, $path);
            $width = imagesx($image);
            $height = imagesy($image);
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $useWebp = function_exists('imagewebp');
        $ext = $useWebp ? 'webp' : 'jpg';
        $widths = [];
        foreach (self::WIDTHS as $w) {
            if ($w <= $width) {
                $widths[] = $w;
            }
        }
        if (!$widths || ($width > max($widths) && $width < 1600 && $width - max($widths) > 200)) {
            $widths[] = $width; // keep the real size when it sits between two steps
        }
        sort($widths);

        foreach ($widths as $w) {
            $h = max(1, (int) round($height * $w / $width));
            $resized = imagecreatetruecolor($w, $h);
            if ($useWebp) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 255, 255, 255, 127));
            } else {
                imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $w, $h, $width, $height);
            $target = self::publicDir() . '/' . $token . '-' . $w . '.' . $ext;
            $ok = $useWebp ? imagewebp($resized, $target, 82) : imagejpeg($resized, $target, 85);
            self::free($resized);
            if (!$ok) {
                throw new RuntimeException('Impossible d\'écrire ' . $target);
            }
        }
        self::free($image);
        return [$width, $height, $widths, $ext];
    }

    /** Phone photos are often stored sideways with an orientation flag. */
    private static function applyExifOrientation($image, string $path)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
        $angles = [3 => 180, 6 => -90, 8 => 90];
        if (isset($angles[$orientation])) {
            $rotated = imagerotate($image, $angles[$orientation], 0);
            if ($rotated !== false) {
                self::free($image);
                return $rotated;
            }
        }
        return $image;
    }

    private static function raiseMemoryLimit(int $pixels): void
    {
        // About 5 bytes per pixel for the decoded image, plus room for the copies.
        $needed = (int) ($pixels * 5 * 1.8) + 64 * 1024 * 1024;
        $current = self::iniBytes((string) ini_get('memory_limit'));
        if ($current > 0 && $current < $needed) {
            @ini_set('memory_limit', (string) min($needed, 1024 * 1024 * 1024));
        }
    }

    /** Free a GD image. imagedestroy() does nothing since PHP 8 and is deprecated in 8.5. */
    private static function free($image): void
    {
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($image);
        }
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        if ($unit === 'g') {
            $number *= 1024 * 1024 * 1024;
        } elseif ($unit === 'm') {
            $number *= 1024 * 1024;
        } elseif ($unit === 'k') {
            $number *= 1024;
        }
        return (int) $number;
    }

    /**
     * Remove the files of a media item. When replacing, the new variants are kept.
     * @param int[] $keepWidths
     */
    private static function deleteFiles(array $media, array $keepWidths = [], string $keepExt = ''): void
    {
        $dir = self::publicDir();
        if ($media['kind'] === 'pdf') {
            @unlink($dir . '/' . $media['token'] . '.pdf');
            return;
        }
        foreach (self::variants($media) as $w) {
            if ($media['web_ext'] === $keepExt && in_array($w, $keepWidths, true)) {
                continue;
            }
            @unlink($dir . '/' . $media['token'] . '-' . $w . '.' . $media['web_ext']);
        }
        @unlink(self::originalsDir() . '/' . $media['token'] . '.' . $media['original_ext']);
    }

    public static function delete(int $id): void
    {
        $media = self::find($id);
        if ($media === null) {
            return;
        }
        Db::transaction(function () use ($id): void {
            Db::run('DELETE FROM media_usage WHERE media_id = ?', [$id]);
            Db::run('DELETE FROM media WHERE id = ?', [$id]);
            Db::run('UPDATE pages SET header_media_id = NULL WHERE header_media_id = ?', [$id]);
            foreach (['logo_media_id', 'favicon_media_id', 'og_media_id'] as $key) {
                if (Settings::get($key) === (string) $id) {
                    Settings::set($key, '');
                }
            }
        });
        self::deleteFiles($media);
    }

    public static function setAlt(int $id, string $alt): void
    {
        Db::run('UPDATE media SET alt = ? WHERE id = ?', [mb_substr(trim($alt), 0, 300, 'UTF-8'), $id]);
    }

    /** @param int[] $mediaIds */
    public static function setUsage(string $context, int $refId, array $mediaIds): void
    {
        Db::run('DELETE FROM media_usage WHERE context = ? AND ref_id = ?', [$context, $refId]);
        foreach (array_unique(array_filter(array_map('intval', $mediaIds))) as $mediaId) {
            Db::run('INSERT OR IGNORE INTO media_usage (media_id, context, ref_id) VALUES (?, ?, ?)', [$mediaId, $context, $refId]);
        }
    }

    /**
     * Where a file is used.
     * @return array<int,array{label:string,url:string}>
     */
    public static function usage(int $mediaId): array
    {
        $list = [];
        $rows = Db::all(
            "SELECT u.context, p.id, p.title FROM media_usage u
             LEFT JOIN pages p ON u.context = 'page' AND p.id = u.ref_id
             WHERE u.media_id = ? ORDER BY p.title",
            [$mediaId]
        );
        foreach ($rows as $row) {
            if ($row['context'] === 'settings') {
                $list[] = ['label' => 'Réglages du site', 'url' => '/admin/reglages'];
            } elseif ($row['id'] !== null) {
                $list[] = ['label' => 'Page « ' . $row['title'] . ' »', 'url' => '/admin/pages/' . $row['id']];
            }
        }
        return $list;
    }

    /** @return array<int,int> media id => number of places using it */
    public static function usageCounts(): array
    {
        $counts = [];
        foreach (Db::all('SELECT media_id, COUNT(*) AS n FROM media_usage GROUP BY media_id') as $row) {
            $counts[(int) $row['media_id']] = (int) $row['n'];
        }
        return $counts;
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return str_replace('.', ',', (string) round($bytes / 1024 / 1024, 1)) . ' Mo';
        }
        return max(1, (int) round($bytes / 1024)) . ' Ko';
    }

    /** Data for the media picker in the editor. */
    public static function toJson(array $media): array
    {
        list($w, $h) = self::displaySize($media);
        return [
            'id' => (int) $media['id'],
            'kind' => $media['kind'],
            'name' => $media['original_name'],
            'alt' => $media['alt'],
            'thumb' => $media['kind'] === 'image' ? self::url($media, 480) : '',
            'url' => self::url($media, 960),
            'width' => $w,
            'height' => $h,
        ];
    }
}
