<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Fixed-window rate limiter stored in the database, so limits hold across sessions, cookies
 * and (when there are several) web servers. Bucket names are hashed so emails/IPs are not stored.
 */
final class RateLimiter
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Has this bucket already used up its attempts in the current window? Does not count an attempt. */
    public function tooManyAttempts(string $bucket, int $max): bool
    {
        $stmt = $this->pdo->prepare('SELECT hits, reset_at FROM rate_limits WHERE bucket = ?');
        $stmt->execute([self::key($bucket)]);
        $row = $stmt->fetch();
        return $row && (int) $row['reset_at'] > time() && (int) $row['hits'] >= $max;
    }

    /** Count one attempt. Returns the number of attempts in the current window, including this one. */
    public function hit(string $bucket, int $windowSeconds): int
    {
        $now = time();
        $key = self::key($bucket);
        // Assignments run left to right: hits is computed against the old reset_at before it changes.
        $this->pdo->prepare('
            INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE
                hits = IF(reset_at <= ?, 1, hits + 1),
                reset_at = IF(reset_at <= ?, VALUES(reset_at), reset_at)
        ')->execute([$key, $now + $windowSeconds, $now, $now]);
        $stmt = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ?');
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn();
    }

    /** Count an attempt and report whether it is within the limit. */
    public function attempt(string $bucket, int $max, int $windowSeconds): bool
    {
        return $this->hit($bucket, $windowSeconds) <= $max;
    }

    public function clear(string $bucket): void
    {
        $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([self::key($bucket)]);
    }

    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE reset_at <= ?');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }

    private static function key(string $bucket): string
    {
        return hash('sha256', $bucket);
    }
}
