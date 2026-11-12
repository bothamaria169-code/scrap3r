<?php
declare(strict_types=1);

/**
 * Database access. Runs on MySQL/MariaDB (local XAMPP) or SQLite (hosted),
 * chosen by DB_DRIVER in .env. The handful of dialect differences live here,
 * so the rest of the app writes a single set of SQL.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static bool $sqliteWriteOpen = false;

    public static function driver(): string
    {
        $configured = strtolower((string) env('DB_DRIVER', ''));
        if ($configured === '') {
            // Not configured: a deployed SQLite file wins, so an upload that
            // lost its .env still finds its data. Otherwise MySQL (local XAMPP).
            return is_file(self::sqlitePath()) ? 'sqlite' : 'mysql';
        }
        return $configured === 'sqlite' ? 'sqlite' : 'mysql';
    }

    /** SQLite file; DB_PATH may be absolute or relative to the project root. */
    public static function sqlitePath(): string
    {
        $path = (string) env('DB_PATH', 'storage/database.sqlite');
        return preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) ? $path : BASE_PATH . '/' . $path;
    }

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (self::driver() === 'sqlite') {
            $path = self::sqlitePath();
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            self::$pdo = self::openSqlite($path, $options);
            self::ensureSqliteSchema();
            Migrator::run(self::$pdo);   // upgrade in place after new code is uploaded
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_NAME', 'scrap3r')
        );
        self::$pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), $options);

        // NOW()/CURDATE() in the app's timezone (shared hosts usually run the
        // MySQL server on UTC or US time), and the same forgiving behaviour as
        // SQLite for over-long text instead of a hard error.
        self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        self::$pdo->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");
        self::ensureMysqlSchema();
        return self::$pdo;
    }

    /** First connection to an empty MySQL database builds the schema: nothing to import by hand. */
    private static function ensureMysqlSchema(): void
    {
        $exists = self::$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if ($exists) {
            return;
        }
        $sql = (string) file_get_contents(BASE_PATH . '/db/schema.mysql.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            self::$pdo->exec($statement);
        }
    }

    /** Open a SQLite file with the pragmas and MySQL-compatible functions the app expects. */
    public static function openSqlite(string $path, array $options = []): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, $options + [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 8000');   // wait for the worker instead of failing
        $pdo->exec('PRAGMA journal_mode = WAL');    // readers don't block the writer

        // NOW() / CURDATE() in the app's timezone, exactly as MySQL returns them.
        // (SQLite's own CURRENT_TIMESTAMP is always UTC, which would put every
        // stored time two hours off in Johannesburg.)
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
        return $pdo;
    }

    /** First connection to an empty file builds the schema: no installer step needed. */
    private static function ensureSqliteSchema(): void
    {
        $exists = self::$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
        if (!$exists) {
            self::$pdo->exec((string) file_get_contents(BASE_PATH . '/db/schema.sqlite.sql'));
        }
    }

    /** @param array<string|int, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_map(static fn($c) => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::conn()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(',', array_map(static fn($c) => "$c = :$c", array_keys($data)));
        $stmt = self::run("UPDATE $table SET $sets WHERE $where", $data + $whereParams);
        return $stmt->rowCount();
    }

    /** Insert a row, or update its other columns if one with the same $keys exists. */
    public static function upsert(string $table, array $data, array $keys): void
    {
        $cols = array_keys($data);
        $update = array_values(array_diff($cols, $keys));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(',', $cols),
            implode(',', array_map(static fn($c) => ':' . $c, $cols))
        );

        $sql .= self::driver() === 'sqlite'
            ? ' ON CONFLICT(' . implode(',', $keys) . ') DO UPDATE SET '
                . implode(',', array_map(static fn($c) => "$c = excluded.$c", $update))
            : ' ON DUPLICATE KEY UPDATE '
                . implode(',', array_map(static fn($c) => "$c = VALUES($c)", $update));

        self::run($sql, $data);
    }

    /** "INSERT IGNORE" in the current dialect: silently skip rows that break a unique key. */
    public static function insertIgnore(): string
    {
        return self::driver() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
    }

    /** Row-lock suffix for a SELECT inside beginWrite(). SQLite already holds the write lock. */
    public static function forUpdate(): string
    {
        return self::driver() === 'sqlite' ? '' : ' FOR UPDATE';
    }

    /**
     * Start a transaction that will write. On SQLite this takes the write lock
     * up front (BEGIN IMMEDIATE), so two people booking the same slot at the
     * same moment cannot both read "free" and both insert.
     */
    public static function beginWrite(): void
    {
        if (self::driver() === 'sqlite') {
            self::conn()->exec('BEGIN IMMEDIATE');
            self::$sqliteWriteOpen = true;
            return;
        }
        self::conn()->beginTransaction();
    }

    public static function commit(): void
    {
        if (self::$sqliteWriteOpen) {
            self::conn()->exec('COMMIT');
            self::$sqliteWriteOpen = false;
            return;
        }
        self::conn()->commit();
    }

    public static function rollback(): void
    {
        if (self::$sqliteWriteOpen) {
            self::conn()->exec('ROLLBACK');
            self::$sqliteWriteOpen = false;
            return;
        }
        if (self::conn()->inTransaction()) {
            self::conn()->rollBack();
        }
    }

    /** Forget the current connection (tests, and the export tool switching databases). */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$sqliteWriteOpen = false;
    }
}
