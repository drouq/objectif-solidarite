<?php
/*
 * Prepares folders to upload by hand (scp or FileZilla) when OVH Git deployment is not available.
 *
 *   tools/php85/php.exe build/prepare-upload.php                  code only: build/upload/os/
 *   tools/php85/php.exe build/prepare-upload.php --with-content   also build/upload/os-private/ (seed + config.php to fill in)
 *
 * build/upload/os/ holds exactly what the server needs from the last commit (git archive HEAD):
 * .htaccess, app/, public/, config.sample.php. Upload it into the server's os/ folder.
 * build/upload/os-private/ is for the FIRST installation only: uploading it again would replace
 * the database and lose every change made in the admin. build/upload/ is git-ignored.
 */
declare(strict_types=1);

$project = dirname(__DIR__);
$out = $project . '/build/upload';
$withContent = in_array('--with-content', $argv, true);

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

// Uncommitted changes are not uploaded: say so.
$dirty = trim((string) shell_exec('git -C ' . escapeshellarg($project) . ' status --porcelain'));
if ($dirty !== '') {
    fwrite(STDERR, "Note: uncommitted changes are NOT included (the folder is made from the last commit).\n");
}

rrmdir($out);
@mkdir($out, 0777, true);
$zip = $out . '/code.zip';
$cmd = 'git -C ' . escapeshellarg($project) . ' archive --format=zip -o ' . escapeshellarg($zip) . ' HEAD .htaccess app public config.sample.php';
exec($cmd, $output, $code);
if ($code !== 0 || !is_file($zip)) {
    fwrite(STDERR, "git archive failed.\n");
    exit(1);
}
$archive = new ZipArchive();
$archive->open($zip);
$archive->extractTo($out . '/os');
$archive->close();
unlink($zip);
$commit = trim((string) shell_exec('git -C ' . escapeshellarg($project) . ' rev-parse --short HEAD'));
file_put_contents($out . '/os/VERSION.txt', 'Commit ' . $commit . ', prepared ' . gmdate('Y-m-d H:i') . " UTC\n");
echo "Code ready in build/upload/os (commit $commit).\n";

if ($withContent) {
    $seed = $project . '/build/seed/os-private';
    if (!is_file($seed . '/data/site.sqlite')) {
        fwrite(STDERR, "No seed: run tools/php73/php.exe build/import-content.php --import first.\n");
        exit(1);
    }
    rcopy($seed, $out . '/os-private');
    $sample = (string) file_get_contents($project . '/config.sample.php');
    $config = str_replace(
        ["'base_url' => 'https://objectif-solidarite.org',"],
        ["'base_url' => 'https://test.objectif-solidarite.org',"],
        $sample
    );
    file_put_contents($out . '/os-private/config.php', $config);
    echo "Content ready in build/upload/os-private (seed + config.php for staging).\n"
        . "Fill in admin_email, google_client_id and google_client_secret in build/upload/os-private/config.php before uploading.\n";
}
