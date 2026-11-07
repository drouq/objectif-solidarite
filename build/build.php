<?php
/*
 * Builds the dist/ folder to upload with FileZilla.
 *   tools/php85/php.exe build/build.php
 *
 * dist/ contains:  app/  www/  config.sample.php  README.md  VERSION.txt
 * dist/ NEVER contains: data/, config.php, uploaded media. An update cannot wipe content.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dist = $root . '/dist';

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/** Copy a folder. $skip: paths relative to $from that are left out. */
function copyTree(string $from, string $to, array $skip = [], string $rel = ''): int
{
    $count = 0;
    if (!is_dir($to)) {
        mkdir($to, 0755, true);
    }
    foreach (scandir($from) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $relPath = ltrim($rel . '/' . $item, '/');
        if (in_array($relPath, $skip, true)) {
            continue;
        }
        $a = $from . '/' . $item;
        $b = $to . '/' . $item;
        if (is_dir($a)) {
            $count += copyTree($a, $b, $skip, $relPath);
        } else {
            copy($a, $b);
            $count++;
        }
    }
    return $count;
}

// Safety checks before building.
$sample = (string) file_get_contents($root . '/config.sample.php');
if (strpos($sample, "'dev_login' => false") === false || strpos($sample, "'env' => 'production'") === false) {
    fwrite(STDERR, "config.sample.php must ship with env=production and dev_login=false\n");
    exit(1);
}
if (!is_file($root . '/public/assets/admin/admin.js')) {
    fwrite(STDERR, "Editor bundle missing: run 'npm install' and 'npm run build' in editor-src first\n");
    exit(1);
}

rrmdir($dist);
mkdir($dist, 0755, true);

$files = copyTree($root . '/app', $dist . '/app');

// Web root: everything in public/ except uploaded media (only its .htaccess is shipped).
$mediaSkip = [];
foreach (scandir($root . '/public/media') ?: [] as $item) {
    if ($item !== '.' && $item !== '..' && $item !== '.htaccess') {
        $mediaSkip[] = 'media/' . $item;
    }
}
$files += copyTree($root . '/public', $dist . '/www', $mediaSkip);

// Minify the public stylesheet (comments and extra spaces removed).
$cssFile = $dist . '/www/assets/css/site.css';
$css = (string) file_get_contents($cssFile);
$css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
$css = (string) preg_replace('/\s+/', ' ', $css);
// Colons are left alone: ".a :focus" and ".a:focus" mean different things.
$css = (string) preg_replace('/\s*([{};,])\s*/', '$1', $css);
$css = str_replace(';}', '}', $css);
file_put_contents($cssFile, trim($css) . "\n");

copy($root . '/config.sample.php', $dist . '/config.sample.php');
copy($root . '/README.md', $dist . '/README.md');
$files += 2;

$commit = trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')));
file_put_contents($dist . '/VERSION.txt', 'Construit le ' . date('Y-m-d H:i') . ($commit !== '' ? ' (commit ' . $commit . ')' : '') . "\n");

// Final checks on the result.
$problems = [];
foreach (['/config.php', '/data', '/www/config.php', '/tests', '/tools', '/editor-src', '/source-content'] as $forbidden) {
    if (file_exists($dist . $forbidden)) {
        $problems[] = $forbidden . ' must not be in dist';
    }
}
$mediaLeft = array_diff(scandir($dist . '/www/media') ?: [], ['.', '..', '.htaccess']);
if ($mediaLeft) {
    $problems[] = 'uploaded media found in dist/www/media';
}
foreach (['/www/.htaccess', '/www/media/.htaccess', '/app/.htaccess', '/www/index.php', '/www/check.php', '/app/bootstrap.php'] as $needed) {
    if (!is_file($dist . $needed)) {
        $problems[] = $needed . ' missing';
    }
}
if ($problems) {
    fwrite(STDERR, "Build problems:\n - " . implode("\n - ", $problems) . "\n");
    exit(1);
}
echo "dist/ built: $files files.\n";
echo "Upload dist/app and dist/www (see README). Never upload config.php or data/ from your computer.\n";
