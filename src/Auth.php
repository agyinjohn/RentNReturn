<?php

declare(strict_types=1);

namespace App;

class Auth
{
    public static function init(array $config): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($config['session']['name']);
            session_set_cookie_params([
                'lifetime' => $config['session']['lifetime'],
                'path' => '/',
                'secure' => (bool) ($config['session']['secure'] ?? false),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function check(array $config): bool
    {
        self::init($config);
        return !empty($_SESSION['user_id']);
    }

    public static function user(array $config): ?array
    {
        if (!self::check($config)) {
            return null;
        }
        $pdo = Database::get($config);
        $stmt = $pdo->prepare('SELECT id, email, name, contact_preference FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
