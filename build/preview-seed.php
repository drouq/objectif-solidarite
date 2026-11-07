<?php
/*
 * Local preview of the imported content, before uploading it.
 *   tools/php85/php.exe build/preview-seed.php [--publish-all]
 *   tools/php85/php.exe -S localhost:8300 -t tests/tmp/preview/os/public tests/tmp/preview/os/public/index.php
 * Then open http://localhost:8300 and http://localhost:8300/admin ("Entrer sans Google", any e-mail
 * is admin@exemple.fr). It is a COPY in tests/tmp/preview/ with the server layout (os/ + os-private/):
 * the seed in build/seed/ is never changed.
 * --publish-all publishes every page in the copy, to see the site as visitors would.
 */
declare(strict_types=1);

$project = dirname(__DIR__);
$seed = $project . '/build/seed/os-private';
$preview = $project . '/tests/tmp/preview';
if (!is_file($seed . '/data/site.sqlite')) {
    fwrite(STDERR, "No seed: run tools/php73/php.exe build/import-content.php --import first.\n");
    exit(1);
}

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
function rcopy(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            is_dir($from . '/' . $item) ? rcopy($from . '/' . $item, $to . '/' . $item) : copy($from . '/' . $item, $to . '/' . $item);
        }
    }
}

rrmdir($preview);
rcopy($project . '/app', $preview . '/os/app');
rcopy($project . '/public', $preview . '/os/public');
rrmdir($preview . '/os/public/media');
copy($project . '/.htaccess', $preview . '/os/.htaccess');
rcopy($seed, $preview . '/os-private');
file_put_contents($preview . '/os-private/config.php', '<?php return ' . var_export([
    'env' => 'development', 'base_url' => 'http://localhost:8300', 'force_https' => false,
    'admin_email' => 'admin@exemple.fr', 'google_client_id' => 'exemple', 'google_client_secret' => 'exemple',
    'dev_login' => true,
], true) . ';');
if (in_array('--publish-all', $argv, true)) {
    $db = new PDO('sqlite:' . $preview . '/os-private/data/site.sqlite');
    $db->exec("UPDATE pages SET status = 'published'");
    $db = null;
}
echo "Preview ready in tests/tmp/preview (os/ + os-private/). Start it with:\n"
    . "  tools/php85/php.exe -S localhost:8300 -t tests/tmp/preview/os/public tests/tmp/preview/os/public/index.php\n";
