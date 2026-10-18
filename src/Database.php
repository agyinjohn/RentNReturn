<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(array $config): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $db = $config['database'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['database'],
            $db['charset']
        );
        self::$pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Store and compare all timestamps in UTC, matching the PHP timezone.
        self::$pdo->exec("SET time_zone = '+00:00'");
        return self::$pdo;
    }
}
