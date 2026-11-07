<?php
declare(strict_types=1);

/**
 * Session for signed-in editors only. Visitors of the public site never get a cookie:
 * the session is started only in /admin, or read when the cookie already exists.
 */
final class Session
{
    public const NAME = 'site_admin';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $idleSeconds = (int) round(((float) Config::get('session_idle_hours')) * 3600);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) max(600, $idleSeconds));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_save_path(DATA_PATH . '/sessions');
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $last = isset($_SESSION['last_activity']) ? (int) $_SESSION['last_activity'] : $now;
        if (isset($_SESSION['user_id']) && $now - $last > $idleSeconds) {
            // Too long without activity: sign out, keep a message for the login page.
            $_SESSION = ['flash' => [['info', 'Votre session a expiré. Reconnectez-vous.']]];
            session_regenerate_id(true);
        }
        $_SESSION['last_activity'] = $now;
    }

    /** Is there a signed-in editor? Used by public pages to preview hidden pages, without creating a cookie. */
    public static function peekUserId(): ?int
    {
        if (!isset($_COOKIE[self::NAME]) || !is_string($_COOKIE[self::NAME])) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9,-]{20,128}$/', $_COOKIE[self::NAME])) {
            return null;
        }
        self::start();
        $id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        session_write_close();
        return $id;
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(self::NAME, '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = [$type, $message];
    }

    /** @return array<int,array{0:string,1:string}> */
    public static function takeFlash(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return is_array($messages) ? $messages : [];
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): bool
    {
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return is_string($sent) && isset($_SESSION['csrf']) && is_string($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $sent);
    }
}
