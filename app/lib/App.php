<?php
declare(strict_types=1);

/** Request handling: error handling, migrations, routing. */
final class App
{
    public static function setupErrorHandling(): void
    {
        error_reporting(E_ALL);
        ini_set('log_errors', '1');
        if (Config::isProduction()) {
            ini_set('display_errors', '0');
        } else {
            ini_set('display_errors', '1');
        }
        $logDir = DATA_PATH . '/logs';
        if (is_dir($logDir) || @mkdir($logDir, 0755, true)) {
            ini_set('error_log', $logDir . '/php-errors.log');
        }
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');
    }

    public static function run(): void
    {
        $path = Http::path();
        $isAdmin = $path === '/admin' || starts_with($path, '/admin/');
        // Used by check.php to test address rewriting. Answers even before config.php exists.
        if ($path === '/__rewrite-check') {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
            echo 'rewrite-ok';
            return;
        }
        try {
            if (!Config::isLoaded()) {
                self::plainError(503, 'Site en cours d\'installation : le fichier config.php est absent. '
                    . 'Copiez config.sample.php en config.php et remplissez-le (voir README).');
                return;
            }
            Http::sendSecurityHeaders($isAdmin);
            // Always run migrations: they do nothing when the database is up to date.
            Migrations::run();

            // www.example.org -> example.org (one address for visitors, search engines and Google sign-in).
            $baseHost = strtolower((string) parse_url((string) Config::get('base_url'), PHP_URL_HOST));
            $requestHost = strtolower((string) preg_replace('/:\d+$/', '', Http::host()));
            if (Config::isProduction() && $baseHost !== '' && $requestHost === 'www.' . $baseHost) {
                Http::redirect(Config::baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/'), 301);
            }

            if (Config::isProduction() && !Http::isHttps() && ($isAdmin || Config::get('force_https') === true)) {
                $target = 'https://' . Http::host() . (string) ($_SERVER['REQUEST_URI'] ?? '/');
                Http::redirect($target, 301);
            }

            if ($isAdmin) {
                AdminController::handle($path);
            } else {
                PublicController::handle($path);
            }
        } catch (HttpStop $stop) {
            if ($stop->status === 404) {
                if ($isAdmin) {
                    self::plainError(404, 'Page introuvable.');
                } else {
                    PublicController::notFound();
                }
            }
            // Other statuses: the response was already sent (redirect, json, file).
        } catch (Throwable $ex) {
            self::logException($ex);
            if (!Config::isProduction()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
                echo get_class($ex) . ': ' . $ex->getMessage() . "\n" . $ex->getFile() . ':' . $ex->getLine()
                    . "\n\n" . $ex->getTraceAsString();
                return;
            }
            self::plainError(500, 'Une erreur est survenue. Réessayez dans quelques instants.');
        }
    }

    public static function logException(Throwable $ex): void
    {
        error_log('[' . gmdate('c') . '] ' . get_class($ex) . ': ' . $ex->getMessage() . ' in '
            . $ex->getFile() . ':' . $ex->getLine() . ' — ' . Http::method() . ' ' . Http::path());
    }

    public static function plainError(int $status, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Erreur ' . $status . '</title>'
            . '<link rel="stylesheet" href="/assets/css/site.css">'
            . '</head><body><main class="container error-page"><h1>Erreur ' . $status . '</h1><p>' . e($message)
            . '</p><p><a href="/">Retour à l\'accueil</a></p></main></body></html>';
    }
}
