<?php
declare(strict_types=1);

/*
 * Loads settings and code. Expects ROOT_PATH and PUBLIC_PATH to be defined.
 * Must run on PHP 7.3 and PHP 8.x: no PHP 8-only syntax or functions.
 */

define('APP_PATH', __DIR__);
define('APP_VERSION', '1.0.0');

$appFiles = [
    'lib/helpers.php',
    'lib/Config.php',
    'lib/Http.php',
    'lib/Db.php',
    'lib/Migrations.php',
    'lib/Settings.php',
    'lib/Slug.php',
    'lib/Sanitizer.php',
    'lib/Embed.php',
    'lib/Media.php',
    'lib/Pages.php',
    'lib/Users.php',
    'lib/Session.php',
    'lib/Auth.php',
    'lib/GoogleOAuth.php',
    'lib/Renderer.php',
    'lib/Backup.php',
    'lib/View.php',
    'lib/App.php',
    'controllers/PublicController.php',
    'controllers/AdminController.php',
];
foreach ($appFiles as $appFile) {
    require APP_PATH . '/' . $appFile;
}
unset($appFiles, $appFile);

Config::load(Config::locate(ROOT_PATH));
if (!defined('DATA_PATH')) {
    define('DATA_PATH', Config::dataPath());
}

App::setupErrorHandling();
