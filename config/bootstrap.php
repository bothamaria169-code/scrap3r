<?php
declare(strict_types=1);

/**
 * Shared bootstrap: env loading, autoloader, error handling.
 * Included by every entry point (web pages and CLI workers alike).
 */

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * Minimal .env reader. Mirrors the Python loader in the scraper so both
 * halves of the system read the same file.
 */
function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

load_env(BASE_PATH . '/.env');

date_default_timezone_set(env('APP_TZ', 'UTC'));

if (env('APP_ENV', 'production') === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('log_errors', '1');
    @mkdir(BASE_PATH . '/storage/logs', 0775, true);
    ini_set('error_log', BASE_PATH . '/storage/logs/php-errors.log');
}

require_once BASE_PATH . '/src/helpers.php';

// A readable page instead of a raw fatal error (which would also print file
// paths). Full details go to the error log; they are shown only when
// APP_ENV=local.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        $isDb = $e instanceof PDOException;
        $title = $isDb ? 'The app could not open its database' : 'Something went wrong';
        $hint = $isDb
            ? 'Check the <code>.env</code> file in the site folder (next to <code>public/</code>). With MySQL it needs '
              . '<code>DB_DRIVER=mysql</code> and the correct <code>DB_NAME</code>, <code>DB_USER</code> and <code>DB_PASS</code>; '
              . 'in cPanel the user must be added to the database with ALL PRIVILEGES. '
              . 'Details are in <code>storage/logs/php-errors.log</code>.'
            : 'The details have been written to <code>storage/logs/php-errors.log</code>.';
        $detail = env('APP_ENV', 'production') === 'local'
            ? '<pre style="white-space:pre-wrap;background:#f4f4f5;padding:12px;border-radius:6px;font-size:12px">'
              . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>'
            : '';

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title></head>'
            . '<body style="font:15px/1.6 Arial,Helvetica,sans-serif;color:#1f2933;max-width:640px;margin:10vh auto;padding:0 20px">'
            . '<h1 style="font-size:22px">' . $title . '</h1><p>' . $hint . '</p>' . $detail . '</body></html>';
    });
}
