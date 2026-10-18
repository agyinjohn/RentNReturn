<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Migrator;
use PDO;

/**
 * Creates a throwaway database (built through the real migration runner) and runs the app on
 * PHP's built-in web server so tests exercise it over HTTP exactly as a browser would.
 */
final class TestEnvironment
{
    public const DB_NAME = 'rentnreturn_test';

    private static ?PDO $pdo = null;
    /** @var array<string, array{proc: resource, url: string}> */
    private static array $servers = [];
    private static ?string $tmpDir = null;

    public static function dbConfig(): array
    {
        return [
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'username' => getenv('TEST_DB_USERNAME') ?: 'root',
            'password' => getenv('TEST_DB_PASSWORD') ?: '',
            'database' => self::DB_NAME,
            'charset' => 'utf8mb4',
        ];
    }

    public static function serverPdo(): PDO
    {
        $db = self::dbConfig();
        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port']), $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** Fresh database built by the migration runner. */
    public static function freshDatabase(): PDO
    {
        $server = self::serverPdo();
        $server->exec('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
        $server->exec('CREATE DATABASE `' . self::DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$pdo = self::pdo(true);
        (new Migrator(self::$pdo, dirname(__DIR__, 2) . '/storage'))->migrate();
        return self::$pdo;
    }

    public static function pdo(bool $reconnect = false): PDO
    {
        if (self::$pdo === null || $reconnect) {
            $db = self::dbConfig();
            self::$pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], $db['port'], $db['database']),
                $db['username'],
                $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function tmpDir(): string
    {
        if (self::$tmpDir === null) {
            self::$tmpDir = sys_get_temp_dir() . '/rentnreturn-tests-' . getmypid();
            @mkdir(self::$tmpDir . '/logs', 0777, true);
        }
        return self::$tmpDir;
    }

    public static function mailLogPath(): string
    {
        return self::tmpDir() . '/mail.log';
    }

    public static function logDir(): string
    {
        return self::tmpDir() . '/logs';
    }

    /** Start (or reuse) a web server running the app with the given environment overrides. Returns its base URL. */
    public static function server(array $envOverrides = []): string
    {
        $key = md5(serialize($envOverrides));
        if (isset(self::$servers[$key])) {
            return self::$servers[$key]['url'];
        }
        $db = self::dbConfig();
        $env = $envOverrides + [
            'APP_ENV' => 'development',
            'APP_DEBUG' => '0',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'],
            'MAIL_DRIVER' => 'log',
            'MAIL_LOG_PATH' => self::mailLogPath(),
            'LOG_PATH' => self::logDir(),
            'AI_PROVIDER' => 'pipeline',
            'GROQ_API_KEY' => '',
            'GEMINI_API_KEY' => '',
            'OPENAI_API_KEY' => '',
            'RATE_AI_PER_HOUR' => '2',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);

        $root = dirname(__DIR__, 2);
        $script = $root . '/tests/' . ($envOverrides['__script'] ?? 'router') . '.php';
        unset($env['__script']);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'upload_max_filesize=6M', '-d', 'post_max_size=32M', '-S', "127.0.0.1:{$port}", '-t', $root . '/public', $script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', self::tmpDir() . "/server-{$port}.log", 'a'], 2 => ['file', self::tmpDir() . "/server-{$port}.log", 'a']],
            $pipes,
            $root,
            $env
        );
        $url = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 100; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($conn) {
                fclose($conn);
                break;
            }
            usleep(50000);
        }
        self::$servers[$key] = ['proc' => $proc, 'url' => $url];
        if (count(self::$servers) === 1) {
            register_shutdown_function([self::class, 'stopServers']);
        }
        return $url;
    }

    /** A fake OpenAI-compatible API (tests/fake_openai.php). Returns its base URL for OPENAI_BASE_URL. */
    public static function fakeAiServer(): string
    {
        return self::server(['__script' => 'fake_openai']) . '/v1';
    }

    /** A base URL nothing listens on, for "AI configured but unreachable". */
    public static function deadUrl(): string
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        return 'http://' . $name . '/v1';
    }

    public static function stopServers(): void
    {
        foreach (self::$servers as $server) {
            proc_terminate($server['proc']);
            proc_close($server['proc']);
        }
        self::$servers = [];
    }

    /** @return array<int, array{to: string, subject: string, body: string}> */
    public static function emails(): array
    {
        if (!is_file(self::mailLogPath())) {
            return [];
        }
        $lines = file(self::mailLogPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(static fn (string $l): array => json_decode($l, true), $lines);
    }

    public static function emailsTo(string $to): array
    {
        return array_values(array_filter(self::emails(), static fn (array $m): bool => $m['to'] === $to));
    }

    public static function clearEmails(): void
    {
        @unlink(self::mailLogPath());
    }
}
