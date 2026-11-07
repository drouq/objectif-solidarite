<?php
declare(strict_types=1);

/**
 * "Télécharger une sauvegarde": one zip with the database, the original images,
 * the public media files and the config template (never the real config.php).
 *
 * Zip layout (same as an install):
 *   data/site.sqlite, data/originals/...   -> the private "data" folder
 *   media/...                              -> the uploaded files folder (Config::mediaPath)
 *   config.sample.php, RESTAURATION.txt
 */
final class Backup
{
    /** Build the zip and return its path (in data/backups). */
    public static function create(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new UserError('L\'extension PHP ZipArchive manque sur le serveur.');
        }
        $dir = DATA_PATH . '/backups';
        ensure_dir($dir);
        foreach (glob($dir . '/*.zip') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
        $zipPath = $dir . '/sauvegarde-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.zip';
        $dbCopy = $dir . '/site-' . bin2hex(random_bytes(6)) . '.sqlite';

        // Copy the database while holding the write lock, so the copy is consistent.
        $pdo = Db::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (!copy(Db::file(), $dbCopy)) {
                throw new RuntimeException('Cannot copy the database');
            }
        } finally {
            $pdo->exec('ROLLBACK');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($dbCopy);
            throw new RuntimeException('Cannot create the zip');
        }
        $zip->addFile($dbCopy, 'data/site.sqlite');
        self::addFolder($zip, Media::originalsDir(), 'data/originals');
        self::addFolder($zip, Media::publicDir(), 'media');
        $sample = ROOT_PATH . '/config.sample.php';
        if (is_file($sample)) {
            $zip->addFile($sample, 'config.sample.php');
        }
        $zip->addFromString('RESTAURATION.txt', self::instructions());
        $zip->close();
        @unlink($dbCopy);
        return $zipPath;
    }

    private static function addFolder(ZipArchive $zip, string $folder, string $prefix): void
    {
        if (!is_dir($folder)) {
            return;
        }
        foreach (scandir($folder) ?: [] as $name) {
            $path = $folder . '/' . $name;
            if ($name === '.' || $name === '..' || !is_file($path)) {
                continue;
            }
            $zip->addFile($path, $prefix . '/' . $name);
        }
    }

    private static function instructions(): string
    {
        return "Sauvegarde du site " . Settings::get('site_name') . "\n"
            . "Créée le " . format_date_fr(now_iso()) . " (heure de Paris).\n\n"
            . "Pour restaurer le site sur une installation propre :\n"
            . "1. Installez le site normalement (voir README) et créez config.php à partir de config.sample.php.\n"
            . "2. Copiez le dossier « data » de cette sauvegarde à la place du dossier « data » du site"
            . " (à côté de config.php).\n"
            . "3. Copiez le dossier « media » de cette sauvegarde à la place du dossier « media » "
            . (Config::isPrivateLayout() ? "à côté de config.php (dossier privé).\n" : "du dossier web.\n")
            . "4. Ouvrez le site : tout est revenu (pages, images, réglages, utilisateurs).\n\n"
            . "Le vrai fichier config.php (identifiants Google) n'est pas dans la sauvegarde, par sécurité.\n"
            . "Gardez-en une copie à part.\n";
    }

    public static function send(string $zipPath): void
    {
        $name = 'sauvegarde-' . Slug::make(Settings::get('site_name')) . '-' . date('Y-m-d') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($zipPath));
        header('Cache-Control: no-store');
        readfile($zipPath);
        @unlink($zipPath);
    }
}
