<?php
declare(strict_types=1);

/**
 * Upgrades a SQLite database in place.
 *
 * db/schema.sqlite.sql is version 1. Every later change is a numbered file in
 * db/migrations/ (002_*.sql, 003_*.sql ...). The database remembers the last
 * version applied in PRAGMA user_version, so uploading new code is enough:
 * the next request applies whatever is missing, once, inside a transaction.
 */
final class Migrator
{
    public const BASELINE = 1;

    /** @return array<int,string> file names applied during this call */
    public static function run(PDO $pdo): array
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();

        // A database created before versioning existed has the full v1 schema.
        if ($version === 0) {
            $hasTables = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();
            if ($hasTables) {
                $pdo->exec('PRAGMA user_version = ' . self::BASELINE);
                $version = self::BASELINE;
            }
        }

        $applied = [];
        foreach (self::pending($version) as $number => $file) {
            $pdo->exec('BEGIN IMMEDIATE');
            try {
                if (str_ends_with($file, '.php')) {
                    // PHP migrations return a function that receives the connection.
                    $step = require $file;
                    $step($pdo);
                } else {
                    $pdo->exec((string) file_get_contents($file));
                }
                $pdo->exec('PRAGMA user_version = ' . (int) $number);
                $pdo->exec('COMMIT');
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw new RuntimeException('Migration ' . basename($file) . ' failed: ' . $e->getMessage(), 0, $e);
            }
            $applied[] = basename($file);
        }
        return $applied;
    }

    public static function latest(): int
    {
        $numbers = array_keys(self::pending(0));
        return $numbers ? max($numbers) : self::BASELINE;
    }

    /** @return array<int,string> version => path, ascending, only those above $current */
    private static function pending(int $current): array
    {
        $found = [];
        $files = array_merge(glob(BASE_PATH . '/db/migrations/*.sql') ?: [], glob(BASE_PATH . '/db/migrations/*.php') ?: []);
        foreach ($files as $file) {
            if (preg_match('/^(\d+)_/', basename($file), $m) && (int) $m[1] > $current) {
                $found[(int) $m[1]] = $file;
            }
        }
        ksort($found);
        return $found;
    }
}
