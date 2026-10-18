<?php

declare(strict_types=1);

namespace App;

/**
 * Converts PHP errors to exceptions, logs every uncaught exception with full detail,
 * and shows users a generic error page with a reference ID (details only when debug is on).
 */
final class ErrorHandler
{
    private static array $config = [];

    public static function register(array $config): void
    {
        self::$config = $config;
        error_reporting(E_ALL);
        ini_set('display_errors', !empty($config['debug']) ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                // Suppressed with @ — leave it to the caller.
                return false;
            }
            if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                Logger::warning('Deprecated: ' . $message, ['file' => $file . ':' . $line]);
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            }
        });
    }

    public static function handle(\Throwable $e): void
    {
        Logger::exception($e);

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
            exit(1);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        $reference = Logger::requestId();
        $debug = !empty(self::$config['debug']);
        $wantsJson = str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/');

        if ($wantsJson) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['error' => 'Something went wrong. Please try again.', 'reference' => $reference]
                + ($debug ? ['debug' => $e->getMessage()] : []));
            return;
        }

        $template = (self::$config['base_path'] ?? dirname(__DIR__)) . '/templates/500.php';
        $errorDetail = $debug ? get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString() : null;
        try {
            include $template;
        } catch (\Throwable) {
            echo 'Something went wrong. Reference: ' . htmlspecialchars($reference);
        }
    }
}
