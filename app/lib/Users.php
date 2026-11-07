<?php
declare(strict_types=1);

/** Allowlist of people who may sign in, with their role (admin or editor). */
final class Users
{
    public const ROLES = ['admin' => 'Administrateur', 'editor' => 'Éditeur'];

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** The admin named in config.php is always allowed, even if removed from the list. */
    public static function isConfigAdmin(string $email): bool
    {
        $configured = self::normalizeEmail((string) Config::get('admin_email'));
        return $configured !== '' && $configured === self::normalizeEmail($email);
    }

    /** @return array<string,mixed>|null */
    public static function findByEmail(string $email): ?array
    {
        return Db::one('SELECT * FROM users WHERE email = ? COLLATE NOCASE', [self::normalizeEmail($email)]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function allUsers(): array
    {
        return Db::all("SELECT * FROM users ORDER BY CASE role WHEN 'admin' THEN 0 ELSE 1 END, email");
    }

    /**
     * Find the allowed account for an e-mail address, creating the config admin on first sign-in.
     * @return array<string,mixed>|null null when the address is not on the list
     */
    public static function allowed(string $email): ?array
    {
        $user = self::findByEmail($email);
        if (self::isConfigAdmin($email)) {
            if ($user === null) {
                $id = Db::insert('users', [
                    'email' => self::normalizeEmail($email),
                    'role' => 'admin',
                    'name' => '',
                    'created_at' => now_iso(),
                ]);
                $user = self::find($id);
            } elseif ($user['role'] !== 'admin') {
                Db::run("UPDATE users SET role = 'admin' WHERE id = ?", [$user['id']]);
                $user['role'] = 'admin';
            }
        }
        return $user;
    }

    public static function add(string $email, string $role): void
    {
        $email = self::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('Adresse e-mail invalide.');
        }
        if (!isset(self::ROLES[$role])) {
            throw new UserError('Rôle invalide.');
        }
        if (self::findByEmail($email) !== null) {
            throw new UserError('Cette personne est déjà dans la liste.');
        }
        Db::insert('users', ['email' => $email, 'role' => $role, 'name' => '', 'created_at' => now_iso()]);
    }

    public static function setRole(int $id, string $role, int $currentUserId): void
    {
        $user = self::find($id);
        if ($user === null || !isset(self::ROLES[$role])) {
            throw new UserError('Modification impossible.');
        }
        if (self::isConfigAdmin((string) $user['email']) && $role !== 'admin') {
            throw new UserError('L\'administrateur principal (défini dans config.php) reste administrateur.');
        }
        if ($id === $currentUserId && $role !== 'admin') {
            throw new UserError('Vous ne pouvez pas retirer vos propres droits d\'administrateur.');
        }
        Db::run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
    }

    public static function remove(int $id, int $currentUserId): void
    {
        $user = self::find($id);
        if ($user === null) {
            return;
        }
        if ($id === $currentUserId) {
            throw new UserError('Vous ne pouvez pas vous retirer vous-même.');
        }
        if (self::isConfigAdmin((string) $user['email'])) {
            throw new UserError('L\'administrateur principal (défini dans config.php) ne peut pas être retiré.');
        }
        Db::run('DELETE FROM users WHERE id = ?', [$id]);
    }

    public static function touchLogin(int $id, string $name): void
    {
        Db::run('UPDATE users SET last_login_at = ?, name = ? WHERE id = ?', [now_iso(), mb_substr($name, 0, 100, 'UTF-8'), $id]);
    }
}
