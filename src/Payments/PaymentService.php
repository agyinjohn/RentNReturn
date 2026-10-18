<?php

declare(strict_types=1);

namespace App\Payments;

use App\Booking\BookingException;
use App\Booking\BookingService;
use App\Booking\BookingStatus;
use App\Logger;
use PDO;

/**
 * Payments for bookings (Stripe Checkout + Connect, separate charges and transfers).
 *
 * Rules:
 *  - Amounts always come from the booking on the server; the browser only says "pay booking X".
 *  - A booking becomes paid/confirmed ONLY from a signed Stripe webhook (handleCheckoutCompleted), never from
 *    the browser returning from Checkout.
 *  - Every money movement is appended to payment_ledger; Stripe calls carry idempotency keys; database rows
 *    have unique keys on Stripe IDs, so retries and duplicate webhooks cannot double-count anything.
 *  - The owner's share is held as a payout and transferred when the rental is completed.
 */
final class PaymentService
{
    public function __construct(
        private PDO $pdo,
        private ?StripeGateway $stripe,
        private BookingService $bookings,
        private array $config
    ) {
    }

    public function enabled(): bool
    {
        return $this->stripe !== null;
    }

    /** @return array{rental: int, fee: int, charge: int, owner: int} */
    public function split(int $rentalPence): array
    {
        return Fees::split($rentalPence, $this->config['payments']['platform_fee_bps'], $this->config['payments']['fee_payer']);
    }

    // ------------------------------------------------------------------ checkout

    /** Renter starts paying an approved booking. Returns the Stripe Checkout URL to redirect to. */
    public function startCheckout(int $renterId, int $requestId): string
    {
        if (!$this->enabled()) {
            throw new PaymentException('Online payment is not available yet.');
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                SELECT b.*, l.title, u.email AS renter_email, u.name AS renter_name, u.stripe_customer_id
                FROM bookings b JOIN listings l ON l.id = b.listing_id JOIN users u ON u.id = b.renter_id
                WHERE b.rental_request_id = ? FOR UPDATE
            ');
            $stmt->execute([$requestId]);
            $booking = $stmt->fetch();
            if (!$booking || (int) $booking['renter_id'] !== $renterId) {
                throw new PaymentException('Booking not found.');
            }
            if ($booking['status'] !== BookingStatus::AWAITING_PAYMENT) {
                throw new PaymentException($booking['status'] === BookingStatus::CONFIRMED ? 'This booking is already paid.' : 'This booking cannot be paid.');
            }
            if ($booking['payment_due_at'] !== null && $booking['payment_due_at'] <= gmdate('Y-m-d H:i:s')) {
                throw new PaymentException('The payment deadline has passed, so this booking has been released.');
            }
            // Reuse an open Checkout Session rather than creating a second one (double clicks, back button).
            $open = $this->pdo->prepare("SELECT * FROM payments WHERE booking_id = ? AND status IN ('created', 'pending') ORDER BY id DESC LIMIT 1");
            $open->execute([$booking['id']]);
            $existing = $open->fetch();
            if ($existing && $existing['status'] === 'pending' && $existing['expires_at'] > gmdate('Y-m-d H:i:s', time() + 120)) {
                $this->pdo->commit();
                return $existing['checkout_url'];
            }
            if ($existing && $existing['status'] === 'created' && strtotime($existing['created_at'] . ' UTC') > time() - 60) {
                throw new PaymentException('Your payment page is being prepared. Please try again in a moment.');
            }
            if ($existing) {
                $this->pdo->prepare("UPDATE payments SET status = 'expired' WHERE id = ?")->execute([$existing['id']]);
            }
            $split = $this->split((int) $booking['total_pence']);
            $this->pdo->prepare('
                INSERT INTO payments (booking_id, rental_pence, platform_fee_pence, amount_pence, owner_amount_pence, status)
                VALUES (?, ?, ?, ?, ?, \'created\')
            ')->execute([$booking['id'], $split['rental'], $split['fee'], $split['charge'], $split['owner']]);
            $paymentId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $session = $this->stripe->createCheckoutSession($this->checkoutParams($booking, $paymentId, $split, $requestId), 'checkout-' . $paymentId);
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE payments SET status = 'failed', failure_reason = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 255), $paymentId]);
            Logger::error('Could not create Stripe Checkout Session', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            throw new PaymentException('We could not start the payment just now. Please try again.');
        }
        $this->pdo->prepare("UPDATE payments SET status = 'pending', checkout_session_id = ?, checkout_url = ?, expires_at = ? WHERE id = ?")
            ->execute([$session['id'], $session['url'], gmdate('Y-m-d H:i:s', (int) $session['expires_at']), $paymentId]);
        return $session['url'];
    }

    private function checkoutParams(array $booking, int $paymentId, array $split, int $requestId): array
    {
        $base = $this->config['url'];
        $metadata = ['booking_id' => (string) $booking['id'], 'payment_id' => (string) $paymentId, 'rental_request_id' => (string) $requestId];
        $description = sprintf('%s to %s (%d day%s)', $booking['start_date'], $booking['end_date'], $booking['days'], (int) $booking['days'] === 1 ? '' : 's');
        $lineItems = [[
            'quantity' => 1,
            'price_data' => [
                'currency' => 'gbp',
                'unit_amount' => $split['charge'] - ($this->config['payments']['fee_payer'] === 'renter' ? $split['fee'] : 0),
                'product_data' => ['name' => 'Rental: ' . $booking['title'], 'description' => $description],
            ],
        ]];
        if ($this->config['payments']['fee_payer'] === 'renter' && $split['fee'] > 0) {
            $lineItems[] = ['quantity' => 1, 'price_data' => ['currency' => 'gbp', 'unit_amount' => $split['fee'], 'product_data' => ['name' => 'RentNReturn service fee']]];
        }
        // Stripe allows a Checkout Session to expire between 30 minutes and 24 hours from now. Aim for the
        // booking's payment deadline; a payment that still lands after the deadline is refunded automatically.
        $due = $booking['payment_due_at'] !== null ? strtotime($booking['payment_due_at'] . ' UTC') : time() + 86400;
        $expiresAt = max(time() + 31 * 60, min($due, time() + 23 * 3600 + 3540));

        return [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'customer' => $this->ensureCustomer((int) $booking['renter_id'], $booking['renter_email'], $booking['renter_name'], $booking['stripe_customer_id']),
            'client_reference_id' => (string) $booking['id'],
            'metadata' => $metadata,
            'payment_intent_data' => [
                'transfer_group' => 'booking_' . $booking['id'],
                'description' => 'RentNReturn booking #' . $booking['id'] . ': ' . $booking['title'],
                'metadata' => $metadata,
            ],
            'expires_at' => $expiresAt,
            'success_url' => $base . '/rentals/' . $requestId . '?payment=success',
            'cancel_url' => $base . '/rentals/' . $requestId . '?payment=cancelled',
        ];
    }

    private function ensureCustomer(int $userId, string $email, string $name, ?string $existing): string
    {
        if ($existing) {
            return $existing;
        }
        $customer = $this->stripe->createCustomer($userId, $email, $name);
        $this->pdo->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ? AND stripe_customer_id IS NULL')->execute([$customer['id'], $userId]);
        return $customer['id'];
    }

    // ------------------------------------------------------------------ webhook outcomes

    /** checkout.session.completed / async_payment_succeeded (signed webhook only). */
    public function handleCheckoutCompleted(array $session): string
    {
        $payment = $this->paymentForSession($session['id'] ?? '');
        if ($payment === null) {
            Logger::warning('Checkout completed for an unknown session', ['session' => $session['id'] ?? null]);
            return 'unknown_session';
        }
        if (($session['payment_status'] ?? '') !== 'paid') {
            // Delayed payment methods: wait for checkout.session.async_payment_succeeded.
            $this->pdo->prepare("UPDATE payments SET status = 'processing' WHERE id = ? AND status IN ('created', 'pending')")->execute([$payment['id']]);
            return 'processing';
        }
        $intentId = (string) ($session['payment_intent'] ?? '');
        if ((int) ($session['amount_total'] ?? -1) !== (int) $payment['amount_pence'] || strtolower((string) ($session['currency'] ?? '')) !== 'gbp') {
            $this->pdo->prepare("UPDATE payments SET status = 'amount_mismatch', payment_intent_id = ? WHERE id = ?")->execute([$intentId ?: null, $payment['id']]);
            Logger::error('Paid amount does not match the booking — not confirming', ['payment_id' => $payment['id'], 'paid' => $session['amount_total'] ?? null]);
            return 'amount_mismatch';
        }

        $chargeId = null;
        try {
            $chargeId = $this->stripe?->retrievePaymentIntent($intentId)['latest_charge'] ?? null;
            $chargeId = is_array($chargeId) ? ($chargeId['id'] ?? null) : $chargeId;
        } catch (\Throwable $e) {
            Logger::warning('Could not look up the charge for a payment', ['payment_id' => $payment['id'], 'error' => $e->getMessage()]);
        }

        // Record the money first (idempotent), then confirm the booking.
        $duplicate = false;
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockPayment((int) $payment['id']);
            if (in_array($row['status'], ['succeeded', 'refunded', 'partially_refunded'], true)) {
                $this->pdo->commit();
                return 'already_recorded';
            }
            $this->pdo->prepare("UPDATE payments SET status = 'succeeded', payment_intent_id = ?, charge_id = ?, paid_at = UTC_TIMESTAMP() WHERE id = ?")
                ->execute([$intentId, $chargeId, $row['id']]);
            $this->ledger($row, 'charge', (int) $row['amount_pence'], $intentId, 'stripe', 'Renter payment');
            $this->ledger($row, 'platform_fee', (int) $row['platform_fee_pence'], $intentId, 'system', 'RentNReturn commission');
            $other = $this->pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND id <> ? AND status IN ('succeeded', 'refunded', 'partially_refunded')");
            $other->execute([$row['booking_id'], $row['id']]);
            $duplicate = (int) $other->fetchColumn() > 0;
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        if ($duplicate) {
            $this->refund((int) $payment['id'], (int) $payment['amount_pence'], 'Duplicate payment for an already-paid booking', 'system');
            return 'duplicate_refunded';
        }
        $result = $this->bookings->confirmPayment((int) $payment['booking_id'], $intentId);
        if ($result === 'confirmed' || $result === 'already_confirmed') {
            $this->createPayout((int) $payment['id']);
            return 'confirmed';
        }
        // Paid after the booking was released (deadline passed / cancelled): give the money back.
        $this->refund((int) $payment['id'], (int) $payment['amount_pence'], 'Payment arrived after the booking was released', 'system');
        return 'late_refunded';
    }

    /** checkout.session.expired: the renter did not finish paying. The booking stays payable until its deadline. */
    public function handleCheckoutExpired(array $session): string
    {
        $payment = $this->paymentForSession($session['id'] ?? '');
        if ($payment === null) {
            return 'unknown_session';
        }
        $stmt = $this->pdo->prepare("UPDATE payments SET status = 'expired' WHERE id = ? AND status IN ('created', 'pending', 'processing')");
        $stmt->execute([$payment['id']]);
        return $stmt->rowCount() === 1 ? 'expired' : 'ignored';
    }

    /** checkout.session.async_payment_failed */
    public function handleCheckoutFailed(array $session): string
    {
        $payment = $this->paymentForSession($session['id'] ?? '');
        if ($payment === null) {
            return 'unknown_session';
        }
        $stmt = $this->pdo->prepare("UPDATE payments SET status = 'failed', failure_reason = 'Payment failed at the bank' WHERE id = ? AND status IN ('created', 'pending', 'processing')");
        $stmt->execute([$payment['id']]);
        return $stmt->rowCount() === 1 ? 'failed' : 'ignored';
    }

    /** refund.created / refund.updated / refund.failed: keep our refund record in step with Stripe. */
    public function handleRefundUpdate(array $refund): string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM refunds WHERE stripe_refund_id = ?');
        $stmt->execute([$refund['id'] ?? '']);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return 'unknown_refund';
        }
        $this->applyRefundStatus((int) $id, (string) $refund['id'], (string) ($refund['status'] ?? ''), $refund['failure_reason'] ?? null);
        return 'updated';
    }

    // ------------------------------------------------------------------ refunds

    /**
     * Refund part or all of a payment. Writes a pending refund record before calling Stripe (so nothing is
     * lost if the call fails), then records the result. Refunds reduce the owner's pending payout in proportion.
     */
    public function refund(int $paymentId, int $amountPence, string $reason, string $actor): int
    {
        if ($amountPence <= 0) {
            throw new PaymentException('Refund amount must be more than zero.');
        }
        $this->pdo->beginTransaction();
        try {
            $payment = $this->lockPayment($paymentId);
            if (!$payment || !in_array($payment['status'], ['succeeded', 'partially_refunded'], true) || !$payment['payment_intent_id']) {
                throw new PaymentException('Only a successful payment can be refunded.');
            }
            $pending = $this->pdo->prepare("SELECT COALESCE(SUM(amount_pence), 0) FROM refunds WHERE payment_id = ? AND status = 'pending'");
            $pending->execute([$paymentId]);
            $refundable = (int) $payment['amount_pence'] - (int) $payment['refunded_pence'] - (int) $pending->fetchColumn();
            if ($amountPence > $refundable) {
                throw new PaymentException('That is more than the amount still refundable (' . \App\Booking\Money::format(max(0, $refundable)) . ').');
            }
            $this->pdo->prepare('INSERT INTO refunds (payment_id, booking_id, amount_pence, status, reason, actor) VALUES (?, ?, ?, \'pending\', ?, ?)')
                ->execute([$paymentId, $payment['booking_id'], $amountPence, mb_substr($reason, 0, 255), $actor]);
            $refundId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $result = $this->stripe->refund($payment['payment_intent_id'], $amountPence, ['booking_id' => (string) $payment['booking_id'], 'refund_id' => (string) $refundId], 'refund-' . $refundId);
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE refunds SET status = 'failed', failure_reason = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 255), $refundId]);
            Logger::error('Stripe refund failed — needs attention', ['refund_id' => $refundId, 'payment_id' => $paymentId, 'error' => $e->getMessage()]);
            throw new PaymentException('The refund could not be processed. It has been recorded and will be retried.');
        }
        $this->applyRefundStatus($refundId, (string) $result['id'], (string) $result['status'], $result['failure_reason'] ?? null);
        return $refundId;
    }

    /** Move a refund to Stripe's status; count it (ledger, refunded total, payout) exactly once when it succeeds. */
    private function applyRefundStatus(int $refundId, string $stripeRefundId, string $status, ?string $failure): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM refunds WHERE id = ? FOR UPDATE');
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch();
            $payment = $this->lockPayment((int) $refund['payment_id']);
            $mapped = match ($status) {
                'succeeded' => 'succeeded',
                'failed', 'canceled' => 'failed',
                default => 'pending',
            };
            if ($refund['status'] === 'succeeded' || ($refund['status'] === $mapped && $refund['stripe_refund_id'] === $stripeRefundId)) {
                $this->pdo->commit();
                return;
            }
            $this->pdo->prepare('UPDATE refunds SET stripe_refund_id = ?, status = ?, failure_reason = ? WHERE id = ?')
                ->execute([$stripeRefundId, $mapped, $failure, $refundId]);
            if ($mapped === 'succeeded') {
                $refunded = (int) $payment['refunded_pence'] + (int) $refund['amount_pence'];
                $this->pdo->prepare('UPDATE payments SET refunded_pence = ?, status = ? WHERE id = ?')
                    ->execute([$refunded, $refunded >= (int) $payment['amount_pence'] ? 'refunded' : 'partially_refunded', $payment['id']]);
                $this->ledger($payment, 'refund', -(int) $refund['amount_pence'], $stripeRefundId, $refund['actor'], $refund['reason']);
                // RentNReturn's share is always charge − owner share (= the fee). After refunds it keeps
                // (charge − refunded) − owner's remaining share; the rest of the commission goes back.
                $ownerLeft = Fees::ownerShareAfterRefund((int) $payment['amount_pence'], (int) $payment['owner_amount_pence'], $refunded);
                $platformLeft = (int) $payment['amount_pence'] - $refunded - $ownerLeft;
                $feeBack = (int) $payment['platform_fee_pence'] - $platformLeft;
                $feeBack -= $this->feeAlreadyReversed((int) $payment['id']);
                if ($feeBack > 0) {
                    $this->ledger($payment, 'platform_fee_reversal', -$feeBack, $stripeRefundId, 'system', 'Commission returned with refund');
                }
                $this->adjustPayoutAfterRefund($payment, $refunded);
            } elseif ($mapped === 'failed') {
                Logger::error('Refund failed at Stripe — needs attention', ['refund_id' => $refundId, 'reason' => $failure]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function feeAlreadyReversed(int $paymentId): int
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(-SUM(amount_pence), 0) FROM payment_ledger WHERE payment_id = ? AND entry_type = 'platform_fee_reversal'");
        $stmt->execute([$paymentId]);
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------ payouts

    private function createPayout(int $paymentId): void
    {
        $stmt = $this->pdo->prepare('SELECT p.*, b.owner_id FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.id = ?');
        $stmt->execute([$paymentId]);
        $p = $stmt->fetch();
        $this->pdo->prepare("INSERT IGNORE INTO payouts (booking_id, payment_id, owner_id, amount_pence, status, note) VALUES (?, ?, ?, ?, 'pending', 'Released to the owner when the rental is completed')")
            ->execute([$p['booking_id'], $paymentId, $p['owner_id'], $p['owner_amount_pence']]);
    }

    /** Called inside applyRefundStatus's transaction. */
    private function adjustPayoutAfterRefund(array $payment, int $refundedPence): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payouts WHERE payment_id = ? FOR UPDATE');
        $stmt->execute([$payment['id']]);
        $payout = $stmt->fetch();
        if (!$payout) {
            return;
        }
        $owner = Fees::ownerShareAfterRefund((int) $payment['amount_pence'], (int) $payment['owner_amount_pence'], $refundedPence);
        if ($payout['status'] === 'transferred') {
            Logger::error('Refund after the owner was already paid — transfer reversal needed (Step 6)', ['payout_id' => $payout['id']]);
            return;
        }
        if (in_array($payout['status'], ['pending', 'awaiting_owner_account', 'failed'], true)) {
            $this->pdo->prepare('UPDATE payouts SET amount_pence = ?, status = ?, note = ? WHERE id = ?')
                ->execute([$owner, $owner === 0 ? 'cancelled' : $payout['status'], $owner === 0 ? 'Cancelled: payment refunded in full' : 'Reduced after a partial refund', $payout['id']]);
        }
    }

    /** Pay the owner their share for a completed booking (or mark it waiting for their Stripe account). */
    public function releasePayout(int $bookingId): string
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                SELECT po.*, b.status AS booking_status, ca.stripe_account_id, ca.payouts_enabled, p.charge_id
                FROM payouts po JOIN bookings b ON b.id = po.booking_id JOIN payments p ON p.id = po.payment_id
                LEFT JOIN connected_accounts ca ON ca.user_id = po.owner_id
                WHERE po.booking_id = ? FOR UPDATE
            ');
            $stmt->execute([$bookingId]);
            $payout = $stmt->fetch();
            // 'processing' can be retried safely: the transfer's idempotency key returns the same transfer.
            if (!$payout || !in_array($payout['status'], ['pending', 'awaiting_owner_account', 'failed', 'processing'], true)) {
                $this->pdo->commit();
                return $payout['status'] ?? 'none';
            }
            if ($payout['booking_status'] !== BookingStatus::COMPLETED) {
                $this->pdo->commit();
                return 'not_completed';
            }
            if (!$payout['stripe_account_id'] || !(int) $payout['payouts_enabled']) {
                $this->pdo->prepare("UPDATE payouts SET status = 'awaiting_owner_account', note = 'Waiting for the owner to finish setting up payouts' WHERE id = ?")->execute([$payout['id']]);
                $this->pdo->commit();
                return 'awaiting_owner_account';
            }
            // Stripe stores errors under an idempotency key too, so a new attempt needs a new key; resuming an
            // attempt that crashed mid-call ('processing') keeps its key, so it can never pay twice.
            $attempt = (int) $payout['attempts'] + ($payout['status'] === 'processing' ? 0 : 1);
            $this->pdo->prepare("UPDATE payouts SET status = 'processing', attempts = ? WHERE id = ?")->execute([$attempt, $payout['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        try {
            $transfer = $this->stripe->transfer((int) $payout['amount_pence'], $payout['stripe_account_id'], 'booking_' . $bookingId, $payout['charge_id'] ?: null,
                ['booking_id' => (string) $bookingId, 'payout_id' => (string) $payout['id']], 'payout-' . $payout['id'] . '-attempt-' . $attempt);
        } catch (\Throwable $e) {
            $this->pdo->prepare("UPDATE payouts SET status = 'failed', note = ? WHERE id = ?")->execute([mb_substr('Transfer failed: ' . $e->getMessage(), 0, 255), $payout['id']]);
            Logger::error('Owner payout transfer failed', ['payout_id' => $payout['id'], 'error' => $e->getMessage()]);
            return 'failed';
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE payouts SET status = 'transferred', stripe_transfer_id = ?, destination_account_id = ?, released_at = UTC_TIMESTAMP(), note = NULL WHERE id = ?")
                ->execute([$transfer['id'], $payout['stripe_account_id'], $payout['id']]);
            $payment = $this->lockPayment((int) $payout['payment_id']);
            $this->ledger($payment, 'transfer', -(int) $payout['amount_pence'], $transfer['id'], 'system', 'Owner payout');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        Logger::info('Owner payout transferred', ['payout_id' => $payout['id'], 'amount' => $payout['amount_pence']]);
        return 'transferred';
    }

    /** Release every payout whose booking is completed (hourly cron, and after an owner finishes onboarding). */
    public function releaseDuePayouts(?int $ownerId = null): array
    {
        $sql = "SELECT po.booking_id FROM payouts po JOIN bookings b ON b.id = po.booking_id
                WHERE po.status IN ('pending', 'awaiting_owner_account', 'failed', 'processing') AND b.status = 'completed'";
        $stmt = $this->pdo->prepare($sql . ($ownerId !== null ? ' AND po.owner_id = ?' : ''));
        $stmt->execute($ownerId !== null ? [$ownerId] : []);
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $bookingId) {
            $results[(int) $bookingId] = $this->releasePayout((int) $bookingId);
        }
        return $results;
    }

    // ------------------------------------------------------------------ cancellations

    /** Owner cancels a paid booking before handover: booking cancelled, renter refunded in full, payout cancelled. */
    public function ownerCancelPaid(int $ownerId, int $requestId): void
    {
        $booking = $this->bookings->cancelPaidByOwner($ownerId, $requestId);
        $stmt = $this->pdo->prepare("SELECT id, amount_pence, refunded_pence FROM payments WHERE booking_id = ? AND status IN ('succeeded', 'partially_refunded') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$booking['id']]);
        $payment = $stmt->fetch();
        if ($payment && (int) $payment['amount_pence'] > (int) $payment['refunded_pence']) {
            $this->refund((int) $payment['id'], (int) $payment['amount_pence'] - (int) $payment['refunded_pence'], 'Owner cancelled the booking', 'owner:' . $ownerId);
        }
    }

    // ------------------------------------------------------------------ reading

    /** Latest payment, refunds and payout for a booking (for the booking page). */
    public function summary(int $bookingId): array
    {
        $p = $this->pdo->prepare('SELECT * FROM payments WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
        $p->execute([$bookingId]);
        $r = $this->pdo->prepare('SELECT amount_pence, status, reason, created_at FROM refunds WHERE booking_id = ? ORDER BY id');
        $r->execute([$bookingId]);
        $o = $this->pdo->prepare('SELECT * FROM payouts WHERE booking_id = ?');
        $o->execute([$bookingId]);
        return ['payment' => $p->fetch() ?: null, 'refunds' => $r->fetchAll(), 'payout' => $o->fetch() ?: null];
    }

    // ------------------------------------------------------------------ internals

    private function paymentForSession(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE checkout_session_id = ?');
        $stmt->execute([$sessionId]);
        return $stmt->fetch() ?: null;
    }

    private function lockPayment(int $paymentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
        $stmt->execute([$paymentId]);
        return $stmt->fetch() ?: null;
    }

    /** Append-only. The unique key on (entry_type, provider_reference) stops the same Stripe object being booked twice. */
    private function ledger(array $payment, string $type, int $amountPence, ?string $reference, string $actor, ?string $reason): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO payment_ledger (payment_id, booking_id, entry_type, amount_pence, provider_reference, actor, reason) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$payment['id'], $payment['booking_id'], $type, $amountPence, $reference, $actor, $reason]);
    }
}
