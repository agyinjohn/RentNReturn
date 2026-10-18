<?php

declare(strict_types=1);

namespace App;

/**
 * Structured JSON-lines logger. One file per day under storage/logs (app-YYYY-MM-DD.log).
 * Each line carries a request ID so a user-facing error reference can be matched to the log entry.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static string $path = '';
    private static int $minLevel = 1;
    private static ?string $requestId = null;

    public static function configure(array $config): void
    {
        self::$path = (string) ($config['log']['path'] ?? '');
        self::$minLevel = self::LEVELS[$config['log']['level'] ?? 'info'] ?? 1;
    }

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function exception(\Throwable $e, array $context = []): void
    {
        self::write('error', $e->getMessage(), $context + [
            'exception' => get_class($e),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);
    }

    private static function write(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < self::$minLevel) {
            return;
        }
        $entry = [
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'message' => $message,
            'request_id' => self::requestId(),
        ];
        if (PHP_SAPI !== 'cli') {
            $entry['method'] = $_SERVER['REQUEST_METHOD'] ?? null;
            $entry['path'] = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $entry['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
                $entry['user_id'] = (int) $_SESSION['user_id'];
            }
        }
        if ($context !== []) {
            $entry['context'] = $context;
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        if (self::$path === '') {
            error_log(rtrim($line));
            return;
        }
        if (!is_dir(self::$path)) {
            @mkdir(self::$path, 0750, true);
        }
        $file = self::$path . '/app-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            // Never let logging failure break the request; fall back to the PHP error log.
            error_log(rtrim($line));
        }
    }
}
