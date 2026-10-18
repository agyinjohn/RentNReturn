<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Short-lived, single-use tokens for email verification and password reset.
 * Only a SHA-256 hash of each token is stored; the plaintext exists only in the emailed link.
 */
final class UserTokens
{
    public const EMAIL_VERIFY = 'email_verify';
    public const PASSWORD_RESET = 'password_reset';

    public function __construct(private PDO $pdo)
    {
    }

    /** Issue a new token, invalidating any earlier unused token for the same user and purpose. */
    public function issue(int $userId, string $purpose, int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $this->invalidate($userId, $purpose);
        $this->pdo->prepare('INSERT INTO user_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $purpose, self::hash($token), gmdate('Y-m-d H:i:s', time() + $ttlSeconds)]);
        return $token;
    }

    /** Return the user ID for a valid (unused, unexpired) token without using it up. */
    public function peek(string $token, string $purpose): ?int
    {
        if (!self::wellFormed($token)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT user_id FROM user_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?');
        $stmt->execute([self::hash($token), $purpose, gmdate('Y-m-d H:i:s')]);
        $userId = $stmt->fetchColumn();
        return $userId === false ? null : (int) $userId;
    }

    /**
     * Use up a token and return its user ID, or null if it is invalid, expired or already used.
     * The conditional UPDATE makes this atomic: two concurrent requests cannot both consume it.
     */
    public function consume(string $token, string $purpose): ?int
    {
        $userId = $this->peek($token, $purpose);
        if ($userId === null) {
            return null;
        }
        $stmt = $this->pdo->prepare('UPDATE user_tokens SET used_at = ? WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?');
        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([$now, self::hash($token), $purpose, $now]);
        return $stmt->rowCount() === 1 ? $userId : null;
    }

    public function invalidate(int $userId, string $purpose): void
    {
        $this->pdo->prepare('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL')
            ->execute([gmdate('Y-m-d H:i:s'), $userId, $purpose]);
    }

    /** Delete tokens that expired or were used more than a week ago. */
    public function purgeOld(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
        $stmt = $this->pdo->prepare('DELETE FROM user_tokens WHERE expires_at < ? OR used_at < ?');
        $stmt->execute([$cutoff, $cutoff]);
        return $stmt->rowCount();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function wellFormed(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
    }
}
