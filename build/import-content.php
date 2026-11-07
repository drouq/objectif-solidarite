<?php
/*
 * Content import (runs on your computer, never on the server).
 *
 *   tools/php73/php.exe build/import-content.php                 check the package, write nothing (= --check)
 *   tools/php73/php.exe build/import-content.php --import        build the seed in build/seed/ (pages hidden)
 *   tools/php73/php.exe build/import-content.php --import --publish   also publish the pages without markers
 *
 * Options: --force (replace an existing seed), --source=<folder> (default source-content),
 * --out=<folder> (default build/seed), --allow-any-php (tests only: import without PHP 7.3).
 *
 * The seed is build/seed/os-private/data/... and build/seed/os-private/media/..., to upload
 * into the server's os-private/ folder (README, "Première mise en ligne du contenu").
 * Use PHP 7.3 for --import: its SQLite (3.28) is the one OVH runs.
 */
declare(strict_types=1);

$project = dirname(__DIR__);
$options = ['check' => false, 'import' => false, 'publish' => false, 'force' => false, 'allow-any-php' => false];
$source = $project . '/source-content';
$out = $project . '/build/seed';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(check|import|publish|force|allow-any-php)$/', $arg, $m)) {
        $options[$m[1]] = true;
    } elseif (preg_match('/^--source=(.+)$/', $arg, $m)) {
        $source = $m[1];
    } elseif (preg_match('/^--out=(.+)$/', $arg, $m)) {
        $out = $m[1];
    } else {
        fwrite(STDERR, "Unknown option: $arg\n");
        exit(2);
    }
}
if ($options['publish'] && !$options['import']) {
    fwrite(STDERR, "--publish only works with --import.\n");
    exit(2);
}
$importing = $options['import'] && !$options['check'];
$out = rtrim(str_replace('\\', '/', $out), '/');
$private = $out . '/os-private';

if ($importing) {
    if (!$options['allow-any-php'] && PHP_VERSION_ID >= 70400) {
        fwrite(STDERR, "Run --import with PHP 7.3 (tools/php73/php.exe), so the database is made by the SQLite version OVH uses.\n");
        exit(2);
    }
    if (is_dir($out) && count(array_diff(scandir($out) ?: [], ['.', '..'])) > 0) {
        if (!$options['force']) {
            fwrite(STDERR, "The seed folder $out is not empty. Add --force to replace it.\n");
            exit(3);
        }
        rrmdir($out);
    }
    @mkdir($private . '/data', 0777, true);
    @mkdir($private . '/media', 0777, true);
    define('DATA_PATH', $private . '/data');
}

define('ROOT_PATH', $project);
define('PUBLIC_PATH', $project . '/public');
if ($importing) {
    require $project . '/app/bootstrap.php';
    ini_set('display_errors', '1');
    ini_set('log_errors', '0');
    Config::override(['media_path' => $private . '/media', 'env' => 'development']);
} else {
    // --check writes nothing: load only the code it needs, no database, no folders.
    foreach (['helpers', 'Config', 'Http', 'Slug', 'Sanitizer', 'Embed', 'Media', 'Pages', 'Markers', 'KeyFigures', 'OldPaths'] as $lib) {
        require $project . '/app/lib/' . $lib . '.php';
    }
    $config = Config::locate($project);
    Config::load($config);
}
require __DIR__ . '/lib/Markdown.php';
require __DIR__ . '/lib/ContentImport.php';

$import = new ContentImport($source);
$import->validate();
$mode = $importing ? 'import' : 'check';

if ($import->failures()) {
    echo $import->report($mode, $options['publish']);
    fwrite(STDERR, "\n" . count($import->failures()) . " validation failure(s). Nothing was imported.\n");
    if ($importing) {
        rrmdir($out);
    }
    exit(1);
}
if (!$importing) {
    echo $import->report('check', false);
    fwrite(STDERR, "\nAll checks pass. Nothing was written (--check).\n");
    exit(0);
}

try {
    $stats = $import->import($options['publish']);
} catch (Throwable $ex) {
    Db::close();
    rrmdir($out);
    fwrite(STDERR, 'Import failed, seed removed: ' . $ex->getMessage() . "\n");
    exit(1);
}
Db::close();
// The seed holds no users, sessions, logs or caches.
foreach (['sessions', 'logs', 'cache', 'backups'] as $sub) {
    foreach (glob($private . '/data/' . $sub . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
$report = $import->report('import', $options['publish'])
    . "\n## Seed\n\n- " . $stats['pages'] . ' pages, ' . $stats['media'] . ' media files, ' . $stats['redirects'] . ' old addresses, ' . $stats['figures'] . " key figures.\n"
    . "- Database: `os-private/data/site.sqlite` (journal mode DELETE, integrity check ok).\n"
    . "- Upload the content of `" . $private . "` into the server's `os-private/` folder (README).\n";
file_put_contents($out . '/import-report.md', $report);
echo $report;
fwrite(STDERR, "\nSeed ready in $private. Report: $out/import-report.md\n");
exit(0);

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            $path = $dir . '/' . $item;
            is_dir($path) ? rrmdir($path) : unlink($path);
        }
    }
    rmdir($dir);
}
