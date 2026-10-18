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

    /** A published listing owned by $ownerId. Returns its ID. */
    protected function createListing(int $ownerId, int $pricePence = 1000, ?string $from = null, ?string $until = null, string $title = 'Test Drill'): int
    {
        $slug = 'test-' . bin2hex(random_bytes(5));
        $this->db()->prepare('
            INSERT INTO listings (user_id, title, slug, category, item_condition, price_daily_pence, final_description, available_now, available_from, available_until, published)
            VALUES (?, ?, ?, \'tools\', \'good\', ?, \'A test item.\', ?, ?, ?, 1)
        ')->execute([$ownerId, $title, $slug, $pricePence, $from === null ? 1 : 0, $from, $until]);
        return (int) $this->db()->lastInsertId();
    }

    /** UK calendar date $offset days from today, as Y-m-d. */
    protected function day(int $offset): string
    {
        return \App\Booking\RentalPeriod::today()->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
    }

    /** Signed-in browser for a new verified user. Returns [browser, userId]. */
    protected function signedInUser(string $prefix = 'user', ?string $url = null): array
    {
        $email = $this->uniqueEmail($prefix);
        $id = $this->createUser($email, 'correct-horse', true, ucfirst($prefix) . ' ' . substr(md5($email), 0, 4));
        $b = new Browser($url ?? static::$url);
        $b->login($email, 'correct-horse');
        return [$b, $id];
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
