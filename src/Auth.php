<?php

declare(strict_types=1);

namespace App;

class Auth
{
    /** @var array|null|false false = not loaded yet this request */
    private static array|null|false $user = false;

    public static function init(array $config): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Reject session IDs the server did not create (prevents session fixation via crafted IDs).
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.gc_maxlifetime', (string) $config['session']['lifetime']);
            session_name($config['session']['name']);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => (bool) ($config['session']['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();

            // Idle timeout: a signed-in session unused for longer than the lifetime is ended.
            $now = time();
            if (!empty($_SESSION['user_id']) && $now - (int) ($_SESSION['last_activity'] ?? $now) > $config['session']['lifetime']) {
                self::logout();
                session_start();
            }
            $_SESSION['last_activity'] = $now;
        }
    }

    public static function check(array $config): bool
    {
        return self::user($config) !== null;
    }

    public static function user(array $config): ?array
    {
        if (self::$user !== false) {
            return self::$user;
        }
        self::init($config);
        if (empty($_SESSION['user_id'])) {
            return self::$user = null;
        }
        $pdo = Database::get($config);
        $stmt = $pdo->prepare('SELECT id, email, email_verified_at, name, contact_preference, session_version FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        // A password change bumps session_version, which signs out every other session for that user.
        if (!$user || (int) $user['session_version'] !== (int) ($_SESSION['session_version'] ?? -1)) {
            self::logout();
            return self::$user = null;
        }
        unset($user['session_version']);
        return self::$user = $user;
    }

    public static function isVerified(?array $user): bool
    {
        return $user !== null && !empty($user['email_verified_at']);
    }

    public static function login(int $userId, int $sessionVersion): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = $sessionVersion;
        $_SESSION['last_activity'] = time();
        // New privilege level, new CSRF token.
        unset($_SESSION['csrf_token']);
        self::$user = false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
    }
}
