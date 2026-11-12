<?php
declare(strict_types=1);

/** HTML-escape. Every value echoed into a template goes through this. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL for in-app navigation, assets and redirects. During a web request it
 * follows the host the browser actually used (localhost, a LAN IP, a domain),
 * so the interface works from any machine that can reach Apache.
 */
function app_url(string $path = ''): string
{
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $dir = rtrim(str_replace('\\', '/', dirname((string) $_SERVER['SCRIPT_NAME'])), '/');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir . '/' . ltrim($path, '/');
    }
    return public_url($path);
}

/**
 * URL for anything that leaves this machine: booking and unsubscribe links in
 * emails. Always built from APP_URL, never from the request, because the lead
 * opening the email is not on your network.
 */
function public_url(string $path = ''): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/' . ltrim($path, '/');
}

/**
 * URL for a file in public/ that changes when the file does, so browsers
 * fetch fresh CSS/JS after an upload instead of running a cached copy.
 */
function asset_url(string $path): string
{
    $file = BASE_PATH . '/public/' . ltrim($path, '/');
    return app_url($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : app_url($path)));
    exit;
}

function random_token(): string
{
    // 32 random bytes -> 43 chars of url-safe base64, matching tokens.token CHAR(43).
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = random_token();
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        // 403 rather than the Laravel-ism 419: Apache will not emit an
        // unregistered status code and turns it into a 500 instead.
        http_response_code(403);
        exit('CSRF token mismatch. Reload the page and try again.');
    }
}

function flash(?string $message = null, string $type = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function is_valid_email(?string $email): bool
{
    return $email !== null
        && $email !== ''
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Render a view file with $data extracted into scope. */
function view(string $__view, array $__vars = []): string
{
    // Unusual local names, so page data can use any key (a key called
    // "data" or "name" used to be silently skipped by EXTR_SKIP).
    extract($__vars, EXTR_SKIP);
    ob_start();
    require BASE_PATH . '/views/' . $__view . '.php';
    return (string) ob_get_clean();
}

function render(string $name, array $data = [], string $layout = 'layout'): never
{
    $content = view($name, $data);
    echo view($layout, $data + ['content' => $content]);
    exit;
}

/**
 * Pull structure out of the ZoomInfo description sentence.
 *
 * In practice Bright Data returns the page's meta description and leaves the
 * structured fields null, so this sentence is the only real content we get.
 * Two shapes show up:
 *
 *   person:  "Eric Pen is Assistant To Chief Financial Officer at Country Garden"
 *   company: "Eskom corporate office is located in <address> and has 10K+ employees"
 *
 * Best-effort by nature — callers should treat the result as a hint, not fact.
 */
function parse_description(?string $description): array
{
    $out = [
        'name' => null, 'title' => null, 'company' => null,
        'headquarters' => null, 'employees' => null,
    ];
    if (!$description) {
        return $out;
    }

    $d = trim($description);

    // Company shape first: it is the more specific pattern, and testing it
    // ahead of the person shape avoids mis-splitting an address on " at ".
    $companyPattern = '/^(.+?)\s+(?:corporate office|head(?:\s?quarters|\s?office))\s+is\s+located\s+(?:in|at)\s+'
        . '(.+?)(?:\s+and\s+has\s+(.+?)\s+employees)?\.?$/iu';

    if (preg_match($companyPattern, $d, $m)) {
        $out['company']      = trim($m[1]);
        $out['headquarters'] = trim($m[2]);
        $out['employees']    = isset($m[3]) && $m[3] !== '' ? trim($m[3]) : null;
        return $out;
    }

    // Looser company variant without the "corporate office" wording.
    if (preg_match('/^(.+?)\s+is\s+located\s+(?:in|at)\s+(.+?)(?:\s+and\s+has\s+(.+?)\s+employees)?\.?$/iu', $d, $m)) {
        $out['company']      = trim($m[1]);
        $out['headquarters'] = trim($m[2]);
        $out['employees']    = isset($m[3]) && $m[3] !== '' ? trim($m[3]) : null;
        return $out;
    }

    // Person shape.
    if (preg_match('/^(.+?)\s+is\s+(.+?)\s+at\s+(.+?)\.?$/iu', $d, $m)) {
        $out['name']    = trim($m[1]);
        $out['title']   = trim($m[2]);
        $out['company'] = trim($m[3]);
    }

    return $out;
}

function money(?string $amount, ?string $currency): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return trim(($currency ?? '') . ' ' . $amount);
}
