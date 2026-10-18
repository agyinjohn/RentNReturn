<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected static string $url;

    public static function setUpBeforeClass(): void
    {
        TestEnvironment::freshDatabase();
        static::$url = TestEnvironment::server();
    }

    protected function setUp(): void
    {
        // Each test starts with no rate-limit history and an empty mailbox.
        $this->db()->exec('DELETE FROM rate_limits');
        TestEnvironment::clearEmails();
    }

    protected function db(): PDO
    {
        return TestEnvironment::pdo();
    }

    protected function browser(): Browser
    {
        return new Browser(static::$url);
    }

    protected function createUser(string $email, string $password = 'correct-horse', bool $verified = true, string $name = 'Test User'): int
    {
        $this->db()->prepare('INSERT INTO users (email, email_verified_at, password_hash, name) VALUES (?, ?, ?, ?)')
            ->execute([$email, $verified ? gmdate('Y-m-d H:i:s') : null, password_hash($password, PASSWORD_DEFAULT), $name]);
        return (int) $this->db()->lastInsertId();
    }

    protected function uniqueEmail(string $prefix = 'user'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4)) . '@example.test';
    }

    /** Extract the first app link containing $path from the latest email to $to. */
    protected function linkFromEmail(string $to, string $path): string
    {
        $emails = TestEnvironment::emailsTo($to);
        $this->assertNotEmpty($emails, "Expected an email to {$to}");
        $body = end($emails)['body'];
        $this->assertMatchesRegularExpression('#https?://[^\s]+' . preg_quote($path, '#') . '[^\s]*#', $body);
        preg_match('#https?://[^/\s]+(' . preg_quote($path, '#') . '[^\s]*)#', $body, $m);
        return $m[1];
    }

    protected function assertRedirectsTo(string $expectedPath, Response $response): void
    {
        $this->assertContains($response->status, [301, 302, 303], 'Expected a redirect, got ' . $response->status);
        $this->assertSame($expectedPath, parse_url((string) $response->location(), PHP_URL_PATH));
    }
}
