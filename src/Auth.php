<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => !empty($_SERVER['HTTPS']),
            ]);
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = Db::one(
            'SELECT * FROM users WHERE email = :e AND is_active = 1',
            ['e' => strtolower(trim($email))]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {   // CLI tools have no session
            session_regenerate_id(true);
        }
        $_SESSION['uid'] = (int) $user['id'];
        return true;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        if (empty($_SESSION['uid'])) {
            return null;
        }
        $cached = Db::one('SELECT * FROM users WHERE id = :id AND is_active = 1', ['id' => $_SESSION['uid']]);
        return $cached;
    }

    public static function require(): array
    {
        $user = self::user();
        if (!$user) {
            redirect('login.php');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::require();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Admins only.');
        }
        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function createUser(string $name, string $email, string $password, string $role = 'rep'): int
    {
        return Db::insert('users', [
            'name'          => $name,
            'email'         => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'timezone'      => date_default_timezone_get(),
            'booking_slug'  => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'rep') . '-' . bin2hex(random_bytes(3)),
        ]);
    }
}
