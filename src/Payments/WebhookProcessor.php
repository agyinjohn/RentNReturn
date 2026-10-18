<?php

declare(strict_types=1);

namespace App\Payments;

use App\Logger;
use PDO;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * Receives Stripe webhooks. This is the ONLY route by which a booking becomes paid.
 *
 *  1. The signature is verified with the endpoint's signing secret (and a 5-minute timestamp tolerance), so
 *     a forged or replayed request is rejected before anything is read.
 *  2. Each event ID is stored once (UNIQUE provider + event_id). A retried or duplicated delivery of an event
 *     that was already processed is acknowledged without doing anything again.
 *  3. Only one delivery processes an event at a time (claimed with a conditional UPDATE).
 *  4. Handlers are themselves idempotent and ignore out-of-order events (e.g. "expired" after "paid").
 */
final class WebhookProcessor
{
    public function __construct(
        private PDO $pdo,
        private string $signingSecret,
        private bool $liveKeys,
        private PaymentService $payments,
        private ?ConnectService $connect
    ) {
    }

    /** @return array{0: int, 1: string} HTTP status and a short body */
    public function handle(string $payload, string $signatureHeader): array
    {
        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->signingSecret)->toArray();
        } catch (SignatureVerificationException | \UnexpectedValueException $e) {
            Logger::warning('Rejected Stripe webhook', ['reason' => $e->getMessage()]);
            return [400, 'invalid signature'];
        }
        if (!empty($event['livemode']) !== $this->liveKeys) {
            Logger::warning('Ignored Stripe webhook from the other mode', ['livemode' => $event['livemode'] ?? null]);
            return [200, 'ignored: wrong mode'];
        }

        $object = $event['data']['object'] ?? [];
        $this->pdo->prepare('
            INSERT INTO webhook_events (provider, event_id, event_type, object_id, status, attempts) VALUES (\'stripe\', ?, ?, ?, \'received\', 1)
            ON DUPLICATE KEY UPDATE attempts = attempts + 1
        ')->execute([$event['id'], $event['type'], $object['id'] ?? null]);

        // Claim the event: only one delivery may process it; a stale claim (crashed worker) can be taken over.
        $claim = $this->pdo->prepare("
            UPDATE webhook_events SET status = 'processing', locked_at = UTC_TIMESTAMP()
            WHERE provider = 'stripe' AND event_id = ?
              AND (status IN ('received', 'failed') OR (status = 'processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE))
        ");
        $claim->execute([$event['id']]);
        if ($claim->rowCount() !== 1) {
            $status = $this->pdo->prepare("SELECT status FROM webhook_events WHERE provider = 'stripe' AND event_id = ?");
            $status->execute([$event['id']]);
            $current = $status->fetchColumn();
            // Already done → acknowledge. Being processed right now → ask Stripe to retry later.
            return in_array($current, ['processed', 'ignored'], true) ? [200, 'duplicate'] : [409, 'in progress'];
        }

        try {
            $outcome = $this->dispatch($event['type'], $object);
            $this->pdo->prepare("UPDATE webhook_events SET status = ?, error = NULL, processed_at = UTC_TIMESTAMP() WHERE provider = 'stripe' AND event_id = ?")
                ->execute([$outcome === 'ignored' ? 'ignored' : 'processed', $event['id']]);
            Logger::info('Stripe webhook processed', ['event' => $event['type'], 'outcome' => $outcome]);
            return [200, $outcome];
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE webhook_events SET status = 'failed', error = ? WHERE provider = 'stripe' AND event_id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 1000), $event['id']]);
            Logger::exception($e, ['webhook_event' => $event['id'], 'type' => $event['type']]);
            return [500, 'error']; // Stripe retries with backoff
        }
    }

    private function dispatch(string $type, array $object): string
    {
        return match ($type) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->payments->handleCheckoutCompleted($object),
            'checkout.session.expired' => $this->payments->handleCheckoutExpired($object),
            'checkout.session.async_payment_failed' => $this->payments->handleCheckoutFailed($object),
            'refund.created', 'refund.updated', 'refund.failed' => $this->payments->handleRefundUpdate($object),
            'account.updated' => $this->accountUpdated($object),
            default => 'ignored',
        };
    }

    private function accountUpdated(array $account): string
    {
        $userId = $this->connect?->sync($account);
        if ($userId === null) {
            return 'unknown_account';
        }
        if (!empty($account['payouts_enabled'])) {
            // Owner just became payable: release anything that was waiting for them.
            $this->payments->releaseDuePayouts($userId);
        }
        return 'account_synced';
    }
}
