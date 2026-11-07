<?php
/*
 * Front controller. Every request that is not a real file ends up here.
 *
 * Local development:  php -S localhost:8000 -t public public/index.php
 */

// PHP built-in server: let it serve real files (css, images...) directly.
if (PHP_SAPI === 'cli-server') {
    $uriPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . str_replace('..', '', rawurldecode($uriPath));
    if ($uriPath !== '/' && basename($file) !== 'index.php' && is_file($file)) {
        return false;
    }
}

define('PUBLIC_PATH', __DIR__);

// Find the folder that holds app/ and config.php:
// next to the web root (safest), or inside it (protected by .htaccess).
$rootPath = null;
foreach ([dirname(__DIR__), __DIR__] as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $rootPath = $candidate;
        break;
    }
}
if ($rootPath === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Installation incomplète : dossier « app » introuvable.";
    exit;
}
define('ROOT_PATH', $rootPath);

require ROOT_PATH . '/app/bootstrap.php';
App::run();
