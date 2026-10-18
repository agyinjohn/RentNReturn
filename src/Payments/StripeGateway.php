<?php

declare(strict_types=1);

namespace App\Payments;

use Stripe\StripeClient;

/**
 * The only class that talks to Stripe. Every create call carries an idempotency key, so a retried request
 * (network blip, double click, webhook retry) can never create a second charge, refund or transfer.
 * Returns plain arrays so the rest of the app does not depend on Stripe SDK objects.
 */
final class StripeGateway
{
    private StripeClient $client;

    public function __construct(array $stripeConfig)
    {
        $this->client = new StripeClient([
            'api_key' => $stripeConfig['secret_key'],
            'api_base' => $stripeConfig['api_base'],
            'max_network_retries' => 2,
        ]);
    }

    public function createCustomer(int $userId, string $email, string $name): array
    {
        return $this->client->customers->create(
            ['email' => $email, 'name' => $name, 'metadata' => ['user_id' => (string) $userId]],
            ['idempotency_key' => 'customer-' . $userId]
        )->toArray();
    }

    public function createExpressAccount(int $userId, string $email, string $country): array
    {
        return $this->client->accounts->create([
            'type' => 'express',
            'country' => $country,
            'email' => $email,
            'capabilities' => ['transfers' => ['requested' => true]],
            'business_type' => 'individual',
            'metadata' => ['user_id' => (string) $userId],
        ], ['idempotency_key' => 'connect-account-' . $userId])->toArray();
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->client->accounts->retrieve($accountId)->toArray();
    }

    public function createOnboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->client->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->url;
    }

    public function createDashboardLink(string $accountId): string
    {
        return $this->client->accounts->createLoginLink($accountId)->url;
    }

    public function createCheckoutSession(array $params, string $idempotencyKey): array
    {
        return $this->client->checkout->sessions->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
    }

    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        return $this->client->paymentIntents->retrieve($paymentIntentId)->toArray();
    }

    public function refund(string $paymentIntentId, int $amountPence, array $metadata, string $idempotencyKey): array
    {
        return $this->client->refunds->create(
            ['payment_intent' => $paymentIntentId, 'amount' => $amountPence, 'metadata' => $metadata],
            ['idempotency_key' => $idempotencyKey]
        )->toArray();
    }

    public function transfer(int $amountPence, string $destination, string $transferGroup, ?string $sourceCharge, array $metadata, string $idempotencyKey): array
    {
        $params = ['amount' => $amountPence, 'currency' => 'gbp', 'destination' => $destination, 'transfer_group' => $transferGroup, 'metadata' => $metadata];
        if ($sourceCharge !== null) {
            // Ties the transfer to the renter's charge, so it works even before those funds are available.
            $params['source_transaction'] = $sourceCharge;
        }
        return $this->client->transfers->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
    }
}
