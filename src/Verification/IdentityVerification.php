<?php

declare(strict_types=1);

namespace App\Verification;

use App\Logger;
use PDO;

/**
 * Identity-verification status. users.identity_verified_at is set ONLY by recordResult(), which is called
 * by a trusted provider callback (or an operator via bin/verification.php) — never from browser input.
 * Every change is kept in user_verifications as history.
 */
final class IdentityVerification
{
    public const STATUSES = ['pending', 'verified', 'failed', 'expired', 'withdrawn'];

    public function __construct(private PDO $pdo, private IdentityProvider $provider)
    {
    }

    public static function providerFromConfig(array $config): IdentityProvider
    {
        return match ($config['identity']['provider']) {
            'simulated' => new SimulatedIdentityProvider(),
            default => new UnavailableIdentityProvider(),
        };
    }

    public function provider(): IdentityProvider
    {
        return $this->provider;
    }

    /** Begin a check. Returns the URL to send the user to. */
    public function start(int $userId, string $returnUrl): string
    {
        $current = $this->current($userId);
        if ($current !== null && $current['status'] === 'verified') {
            throw new VerificationException('Your identity is already verified.');
        }
        $session = $this->provider->start($userId, $returnUrl);
        $this->pdo->prepare("INSERT INTO user_verifications (user_id, type, provider, status, provider_reference) VALUES (?, 'identity', ?, 'pending', ?)")
            ->execute([$userId, $this->provider->name(), $session['reference']]);
        return $session['redirect_url'];
    }

    /**
     * Apply a provider's outcome for one of its checks. Only call with results the server has verified came from
     * the provider (signed webhook / API lookup). Returns the user ID, or null if the reference is unknown.
     */
    public function recordResult(string $provider, string $reference, string $status, ?string $detail = null): ?int
    {
        if (!in_array($status, ['verified', 'failed', 'expired', 'withdrawn'], true)) {
            throw new \InvalidArgumentException("Unknown identity status '{$status}'");
        }
        $stmt = $this->pdo->prepare("SELECT id, user_id, status FROM user_verifications WHERE type = 'identity' AND provider = ? AND provider_reference = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$provider, $reference]);
        $row = $stmt->fetch();
        if (!$row) {
            Logger::warning('Identity result for unknown reference', ['provider' => $provider]);
            return null;
        }
        $userId = (int) $row['user_id'];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('
                UPDATE user_verifications
                SET status = ?, detail = ?, verified_at = IF(? = \'verified\', UTC_TIMESTAMP(), verified_at),
                    failed_at = IF(? IN (\'failed\', \'expired\', \'withdrawn\'), UTC_TIMESTAMP(), failed_at)
                WHERE id = ?
            ')->execute([$status, $detail, $status, $status, $row['id']]);
            if ($status === 'verified') {
                $this->pdo->prepare('UPDATE users SET identity_verified_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]);
            } elseif ($this->latestIdentityRowId($userId) === (int) $row['id']) {
                // A failure/expiry/withdrawal of the user's current check removes the verified status (and the badge).
                $this->pdo->prepare('UPDATE users SET identity_verified_at = NULL WHERE id = ?')->execute([$userId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        Logger::info('Identity verification result', ['user_id' => $userId, 'status' => $status, 'provider' => $provider]);
        return $userId;
    }

    /** Remove a user's identity verification (e.g. account risk), keeping a record of who did it and why. */
    public function withdraw(int $userId, string $actor, string $reason): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE users SET identity_verified_at = NULL WHERE id = ?')->execute([$userId]);
            $this->pdo->prepare("INSERT INTO user_verifications (user_id, type, provider, status, detail, failed_at) VALUES (?, 'identity', ?, 'withdrawn', ?, UTC_TIMESTAMP())")
                ->execute([$userId, $actor, mb_substr($reason, 0, 255)]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        Logger::warning('Identity verification withdrawn', ['user_id' => $userId, 'actor' => $actor]);
    }

    /** Latest identity check for the user (status, provider, timestamps), or null if none. */
    public function current(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT status, provider, provider_reference, verified_at, failed_at, created_at FROM user_verifications WHERE user_id = ? AND type = 'identity' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** Does this pending check belong to this user? (Used by the development simulator page.) */
    public function isPendingFor(int $userId, string $reference): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM user_verifications WHERE user_id = ? AND type = 'identity' AND provider = ? AND provider_reference = ? AND status = 'pending'");
        $stmt->execute([$userId, $this->provider->name(), $reference]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function latestIdentityRowId(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT MAX(id) FROM user_verifications WHERE user_id = ? AND type = 'identity'");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
