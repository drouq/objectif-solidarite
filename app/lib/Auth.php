<?php
declare(strict_types=1);

/** Who is signed in, and what they may do. */
final class Auth
{
    /** @var array<string,mixed>|null|false false = not checked yet */
    private static $user = false;

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        if (self::$user === false) {
            self::$user = null;
            if (isset($_SESSION['user_id'])) {
                // Read the list again on every request: a removed editor loses access at once.
                $user = Users::find((int) $_SESSION['user_id']);
                if ($user !== null) {
                    self::$user = $user;
                } else {
                    unset($_SESSION['user_id']);
                }
            }
        }
        return self::$user;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();
        return $user !== null && $user['role'] === 'admin';
    }

    public static function email(): string
    {
        $user = self::user();
        return $user === null ? '' : (string) $user['email'];
    }

    public static function login(array $user, string $name): void
    {
        Session::regenerate();
        $csrf = bin2hex(random_bytes(32));
        $_SESSION = [
            'user_id' => (int) $user['id'],
            'last_activity' => time(),
            'csrf' => $csrf,
        ];
        Users::touchLogin((int) $user['id'], $name);
        self::$user = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
    }

    /** For tests. */
    public static function reset(): void
    {
        self::$user = false;
    }

    public static function isDevLoginAllowed(): bool
    {
        return Config::get('dev_login') === true
            && !Config::isProduction()
            && Http::isLocalRequest();
    }

    // ----- Throttling of failed sign-in callbacks -----

    private const FAILURE_WINDOW = 900; // 15 minutes
    private const FAILURE_LIMIT = 10;

    private static function ipHash(): string
    {
        $salt = (string) Config::get('google_client_secret') . DATA_PATH;
        return hash('sha256', $salt . '|' . Http::clientIp());
    }

    public static function tooManyFailures(): bool
    {
        Db::run('DELETE FROM auth_failures WHERE created_at < ?', [time() - 86400]);
        $count = (int) Db::value(
            'SELECT COUNT(*) FROM auth_failures WHERE ip_hash = ? AND created_at > ?',
            [self::ipHash(), time() - self::FAILURE_WINDOW]
        );
        return $count >= self::FAILURE_LIMIT;
    }

    public static function recordFailure(): void
    {
        Db::insert('auth_failures', ['ip_hash' => self::ipHash(), 'created_at' => time()]);
    }
}
