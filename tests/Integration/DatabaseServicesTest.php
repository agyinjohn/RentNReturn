<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Migrator;
use App\RateLimiter;
use App\UserTokens;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/** Rate limiter, token store and migration runner against a real MySQL/MariaDB database. */
final class DatabaseServicesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestEnvironment::freshDatabase();
    }

    public function testRateLimiterCountsWithinWindowAndResetsAfterIt(): void
    {
        $limiter = new RateLimiter(TestEnvironment::pdo());
        $bucket = 'test:' . bin2hex(random_bytes(4));
        $this->assertTrue($limiter->attempt($bucket, 2, 60));
        $this->assertTrue($limiter->attempt($bucket, 2, 60));
        $this->assertFalse($limiter->attempt($bucket, 2, 60));
        $this->assertTrue($limiter->tooManyAttempts($bucket, 2));

        // Window elapsed: counting starts again.
        TestEnvironment::pdo()->prepare('UPDATE rate_limits SET reset_at = ? WHERE bucket = ?')->execute([time() - 1, hash('sha256', $bucket)]);
        $this->assertFalse($limiter->tooManyAttempts($bucket, 2));
        $this->assertSame(1, $limiter->hit($bucket, 60));

        $limiter->clear($bucket);
        $this->assertFalse($limiter->tooManyAttempts($bucket, 1));
    }

    public function testRateLimiterStoresOnlyHashedBuckets(): void
    {
        (new RateLimiter(TestEnvironment::pdo()))->hit('login:email:someone@example.test', 60);
        $rows = TestEnvironment::pdo()->query('SELECT bucket FROM rate_limits')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains(hash('sha256', 'login:email:someone@example.test'), $rows);
        $this->assertStringNotContainsString('someone', implode(',', $rows));
    }

    public function testTokensAreSingleUsePurposeBoundAndExpire(): void
    {
        $pdo = TestEnvironment::pdo();
        $pdo->exec("INSERT INTO users (email, password_hash, name) VALUES ('tokens@example.test', 'x', 'T')");
        $userId = (int) $pdo->lastInsertId();
        $tokens = new UserTokens($pdo);

        $token = $tokens->issue($userId, UserTokens::PASSWORD_RESET, 3600);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertNull($tokens->peek($token, UserTokens::EMAIL_VERIFY), 'A reset token must not verify email');
        $this->assertSame($userId, $tokens->peek($token, UserTokens::PASSWORD_RESET));
        $this->assertSame($userId, $tokens->consume($token, UserTokens::PASSWORD_RESET));
        $this->assertNull($tokens->consume($token, UserTokens::PASSWORD_RESET), 'Second use must fail');

        $expired = $tokens->issue($userId, UserTokens::EMAIL_VERIFY, -1);
        $this->assertNull($tokens->consume($expired, UserTokens::EMAIL_VERIFY));

        $first = $tokens->issue($userId, UserTokens::EMAIL_VERIFY, 3600);
        $second = $tokens->issue($userId, UserTokens::EMAIL_VERIFY, 3600);
        $this->assertNull($tokens->peek($first, UserTokens::EMAIL_VERIFY), 'Issuing a new token invalidates the old one');
        $this->assertSame($userId, $tokens->peek($second, UserTokens::EMAIL_VERIFY));
    }

    public function testMigrationsUpgradeAnMvpDatabaseToMatchAFreshInstall(): void
    {
        $server = TestEnvironment::serverPdo();
        $fresh = $this->columnsAndIndexes(TestEnvironment::DB_NAME);

        // Simulated pre-Phase-2 MVP database (ENUM booking status, duplicate slugs, no verification columns).
        $mvpDb = TestEnvironment::DB_NAME . '_mvp';
        $server->exec("DROP DATABASE IF EXISTS `{$mvpDb}`");
        $server->exec("CREATE DATABASE `{$mvpDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $server->exec("USE `{$mvpDb}`");
        foreach (\App\SqlScript::split((string) file_get_contents(dirname(__DIR__) . '/fixtures/mvp_schema.sql')) as $statement) {
            $server->exec($statement);
        }

        $migrator = new Migrator($server, dirname(__DIR__, 2) . '/storage');
        $this->assertSame(['2026_10_05_phase2_foundation', '2026_10_18_step1_accounts', '2026_10_18_step2_bookings', '2026_10_18_step3_verification'], $migrator->migrate());
        $this->assertSame([], $migrator->migrate(), 'Second run must be a no-op');

        $this->assertSame(['drill', 'drill-2', 'drill-3', 'saw'], $server->query('SELECT slug FROM listings ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('approved_awaiting_payment', $server->query('SELECT status FROM bookings')->fetchColumn());

        $migrated = $this->columnsAndIndexes($mvpDb);
        $this->assertSame($fresh['columns'], $migrated['columns'], 'Every column must match a fresh install');
        foreach (['uq_listings_slug', 'idx_bookings_listing_dates_status', 'uq_user_tokens_hash'] as $index) {
            $this->assertContains($index, $migrated['indexes']);
        }
        $server->exec("DROP DATABASE `{$mvpDb}`");
    }

    private function columnsAndIndexes(string $db): array
    {
        $pdo = TestEnvironment::serverPdo();
        $stmt = $pdo->prepare("SELECT CONCAT_WS(' ', table_name, column_name, column_type, is_nullable, IFNULL(column_default, 'NULL')) FROM information_schema.columns WHERE table_schema = ? ORDER BY table_name, column_name");
        $stmt->execute([$db]);
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare('SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = ?');
        $stmt->execute([$db]);
        return ['columns' => $columns, 'indexes' => $stmt->fetchAll(\PDO::FETCH_COLUMN)];
    }
}
