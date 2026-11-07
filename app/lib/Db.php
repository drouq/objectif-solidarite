<?php
declare(strict_types=1);

/** SQLite access through PDO. Prepared statements only. */
final class Db
{
    /** @var PDO|null */
    private static $pdo;

    /** @var bool */
    private static $inTransaction = false;

    public static function file(): string
    {
        return DATA_PATH . '/site.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!extension_loaded('pdo_sqlite')) {
                throw new RuntimeException('Extension PHP pdo_sqlite manquante.');
            }
            ensure_dir(DATA_PATH);
            $pdo = new PDO('sqlite:' . self::file(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 10,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /** Close the connection (tests, backup). */
    public static function close(): void
    {
        self::$pdo = null;
        self::$inTransaction = false;
    }

    /** @param array<int|string,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return mixed
     */
    public static function value(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(string $table, int $id, array $data): void
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = ?';
        }
        $params = array_values($data);
        $params[] = $id;
        self::run('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /**
     * Run a function inside a transaction. Nested calls join the outer one.
     * @return mixed
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        if (self::$inTransaction) {
            return $fn();
        }
        // BEGIN IMMEDIATE takes the write lock at once, so two editors saving
        // at the same moment wait for each other instead of failing.
        $pdo->exec('BEGIN IMMEDIATE');
        self::$inTransaction = true;
        try {
            $result = $fn();
            $pdo->exec('COMMIT');
            self::$inTransaction = false;
            return $result;
        } catch (Throwable $ex) {
            self::$inTransaction = false;
            $pdo->exec('ROLLBACK');
            throw $ex;
        }
    }
}
