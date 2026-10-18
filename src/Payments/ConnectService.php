<?php

declare(strict_types=1);

namespace App\Payments;

use App\Logger;
use PDO;

/**
 * Owners receive payouts through a Stripe Connect Express account. Stripe runs the onboarding and KYC; we keep
 * only the account ID and its capability flags — separate from the public RentNReturn Verified badge.
 */
final class ConnectService
{
    public function __construct(private PDO $pdo, private StripeGateway $stripe, private array $config)
    {
    }

    /** Returns the Stripe-hosted onboarding URL (creating the owner's account on first use). */
    public function onboardingUrl(array $user): string
    {
        $account = $this->account((int) $user['id']);
        if ($account === null) {
            $created = $this->stripe->createExpressAccount((int) $user['id'], $user['email'], $this->config['stripe']['connect_country']);
            $this->pdo->prepare('INSERT IGNORE INTO connected_accounts (user_id, stripe_account_id) VALUES (?, ?)')->execute([$user['id'], $created['id']]);
            $this->sync($created);
            $account = $this->account((int) $user['id']);
        }
        $base = $this->config['url'];
        return $this->stripe->createOnboardingLink($account['stripe_account_id'], $base . '/payouts/refresh', $base . '/payouts/return');
    }

    /** Re-read the owner's account from Stripe (after they return from onboarding). */
    public function refresh(int $userId): ?array
    {
        $account = $this->account($userId);
        if ($account === null) {
            return null;
        }
        $this->sync($this->stripe->retrieveAccount($account['stripe_account_id']));
        return $this->account($userId);
    }

    public function dashboardUrl(int $userId): string
    {
        $account = $this->account($userId);
        if ($account === null || !(int) $account['details_submitted']) {
            throw new PaymentException('Finish setting up payouts first.');
        }
        return $this->stripe->createDashboardLink($account['stripe_account_id']);
    }

    /**
     * Store an account's capability flags from a Stripe Account object (API response or account.updated webhook).
     * Returns the owner's user ID, or null if the account is not one of ours.
     */
    public function sync(array $account): ?int
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM connected_accounts WHERE stripe_account_id = ?');
        $stmt->execute([$account['id'] ?? '']);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            return null;
        }
        $due = $account['requirements']['currently_due'] ?? [];
        $this->pdo->prepare('UPDATE connected_accounts SET details_submitted = ?, charges_enabled = ?, payouts_enabled = ?, requirements_due = ? WHERE stripe_account_id = ?')
            ->execute([
                !empty($account['details_submitted']) ? 1 : 0,
                !empty($account['charges_enabled']) ? 1 : 0,
                !empty($account['payouts_enabled']) ? 1 : 0,
                $due ? mb_substr(implode(', ', $due), 0, 1000) : null,
                $account['id'],
            ]);
        Logger::info('Connected account synced', ['user_id' => (int) $userId, 'payouts_enabled' => !empty($account['payouts_enabled'])]);
        return (int) $userId;
    }

    public function account(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM connected_accounts WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }
}
