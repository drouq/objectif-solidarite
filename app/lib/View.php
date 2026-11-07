<?php
declare(strict_types=1);

/** Renders PHP templates. Variables are passed as an array; output must be escaped with e(). */
final class View
{
    /** @param array<string,mixed> $vars */
    public static function render(string $template, array $vars = []): string
    {
        $file = APP_PATH . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (Throwable $ex) {
            ob_end_clean();
            throw $ex;
        }
        return (string) ob_get_clean();
    }

    /** Public theme template (app/theme/...). @param array<string,mixed> $vars */
    public static function theme(string $template, array $vars = []): string
    {
        return self::render('theme/' . $template, $vars);
    }

    /** Admin template inside the admin layout. @param array<string,mixed> $vars */
    public static function admin(string $template, array $vars = []): string
    {
        $vars['content'] = self::render('admin/' . $template, $vars);
        return self::render('admin/layout', $vars);
    }
}
