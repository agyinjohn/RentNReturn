<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Booking\BookingService;
use Tests\Support\Browser;
use Tests\Support\FakeStripe;
use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 4 gate: Stripe Connect payments in test mode, against a local fake Stripe API. */
final class PaymentFlowTest extends IntegrationTestCase
{
    private static FakeStripe $stripe;
    private static string $app;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$stripe = FakeStripe::start();
        self::$app = TestEnvironment::server(self::$stripe->appEnv());
    }

    private function bookings(): BookingService
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        return new BookingService($this->db(), $config['bookings']);
    }

    /** Approved booking (awaiting payment) for a £15.99 × 3 rental. Returns [renter browser, owner browser, requestId, bookingId, ownerId]. */
    private function approvedBooking(?string $app = null): array
    {
        $app ??= self::$app;
        [$owner, $ownerId] = $this->signedInUser('owner', $app);
        [$renter, $renterId] = $this->signedInUser('renter', $app);
        $listingId = $this->createListing($ownerId, 1599);
        $requestId = $this->bookings()->requestRental($renterId, $listingId, $this->day(5), $this->day(7), null);
        $bookingId = $this->bookings()->approve($ownerId, $requestId);
        return [$renter, $owner, $requestId, $bookingId, $ownerId];
    }

    /** Renter clicks Pay; returns the payments row created. */
    private function startCheckout(Browser $renter, int $requestId, array $extra = []): array
    {
        $r = $renter->post("/rentals/{$requestId}/pay", $extra);
        $this->assertSame(303, $r->status, 'Pay should redirect to Stripe Checkout');
        $this->assertStringStartsWith('https://checkout.stripe.test/c/pay/cs_test_', (string) $r->location());
        return $this->db()->query("SELECT * FROM payments WHERE checkout_session_id = '" . basename((string) $r->location()) . "'")->fetch();
    }

    /** The checkout.session.completed object Stripe would send for this payment. */
    private function paidSession(array $payment, array $overrides = []): array
    {
        return $overrides + [
            'id' => $payment['checkout_session_id'], 'object' => 'checkout.session', 'status' => 'complete', 'payment_status' => 'paid',
            'amount_total' => (int) $payment['amount_pence'], 'currency' => 'gbp', 'payment_intent' => 'pi_' . substr($payment['checkout_session_id'], 8),
            'metadata' => ['booking_id' => (string) $payment['booking_id'], 'payment_id' => (string) $payment['id']],
        ];
    }

    private function webhook(string $type, array $object, array $overrides = [], ?string $app = null): array
    {
        [$payload, $sig] = FakeStripe::event($type, $object, $overrides);
        return FakeStripe::deliver($app ?? self::$app, $payload, $sig);
    }

    private function bookingStatus(int $bookingId): string
    {
        return (string) $this->db()->query("SELECT status FROM bookings WHERE id = {$bookingId}")->fetchColumn();
    }

    private function ledger(int $bookingId): array
    {
        return $this->db()->query("SELECT entry_type, amount_pence FROM payment_ledger WHERE booking_id = {$bookingId} ORDER BY id")->fetchAll(\PDO::FETCH_NUM);
    }

    /** Owner with a fully enabled Stripe account (onboarding done). Returns the account ID. */
    private function onboard(Browser $owner, int $ownerId, ?string $app = null): string
    {
        $r = $owner->post('/payouts/onboard');
        $this->assertSame(303, $r->status);
        $this->assertStringStartsWith('https://connect.stripe.test/setup/acct_', (string) $r->location());
        $account = (string) $this->db()->query("SELECT stripe_account_id FROM connected_accounts WHERE user_id = {$ownerId}")->fetchColumn();
        [$status] = $this->webhook('account.updated', ['id' => $account, 'object' => 'account', 'details_submitted' => true, 'charges_enabled' => true,
            'payouts_enabled' => true, 'requirements' => ['currently_due' => []]], [], $app);
        $this->assertSame(200, $status);
        return $account;
    }

    // ------------------------------------------------------------------------------------------------

    public function testCheckoutUsesServerAmountsAndOnlyASignedWebhookConfirms(): void
    {
        [$renter, $owner, $requestId, $bookingId] = $this->approvedBooking();
        $this->assertStringContainsString('Pay £47.97 securely', $renter->get("/rentals/{$requestId}")->body);
        $this->assertStringContainsString('You receive', $owner->get("/rentals/{$requestId}")->body);

        // The browser cannot influence the amount.
        $payment = $this->startCheckout($renter, $requestId, ['amount' => '1', 'unit_amount' => '1', 'amount_pence' => '1']);
        $this->assertSame(['rental' => 4797, 'fee' => 480, 'charge' => 4797, 'owner' => 4317],
            ['rental' => (int) $payment['rental_pence'], 'fee' => (int) $payment['platform_fee_pence'], 'charge' => (int) $payment['amount_pence'], 'owner' => (int) $payment['owner_amount_pence']]);
        $create = self::$stripe->requests('/v1/checkout/sessions');
        $sent = end($create);
        $this->assertSame('4797', $sent['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('gbp', $sent['params']['line_items'][0]['price_data']['currency']);
        $this->assertSame('booking_' . $bookingId, $sent['params']['payment_intent_data']['transfer_group']);
        $this->assertSame((string) $bookingId, $sent['params']['metadata']['booking_id']);
        $this->assertSame('checkout-' . $payment['id'], $sent['idempotency_key']);
        $this->assertStringStartsWith('cus_', $sent['params']['customer']);
        $this->assertLessThanOrEqual(strtotime($this->db()->query("SELECT payment_due_at FROM bookings WHERE id = {$bookingId}")->fetchColumn() . ' UTC'), (int) $sent['params']['expires_at']);

        // Coming back from Checkout does NOT confirm anything.
        $page = $renter->get("/rentals/{$requestId}?payment=success")->body;
        $this->assertStringContainsString("we're confirming your payment", $page);
        $this->assertSame('approved_awaiting_payment', $this->bookingStatus($bookingId));

        // A forged webhook is rejected.
        [$payload] = FakeStripe::event('checkout.session.completed', $this->paidSession($payment));
        $this->assertSame(400, FakeStripe::deliver(self::$app, $payload, FakeStripe::sign($payload, null, 'whsec_wrong'))[0]);
        $this->assertSame(400, FakeStripe::deliver(self::$app, $payload, 't=' . time() . ',v1=deadbeef')[0]);
        $this->assertSame(400, FakeStripe::deliver(self::$app, $payload, '')[0]);
        $this->assertSame('approved_awaiting_payment', $this->bookingStatus($bookingId));

        // The real, signed webhook confirms the booking and records the money.
        [$payload, $sig] = FakeStripe::event('checkout.session.completed', $this->paidSession($payment));
        $this->assertSame([200, 'confirmed'], FakeStripe::deliver(self::$app, $payload, $sig));
        $this->assertSame('confirmed', $this->bookingStatus($bookingId));
        $this->assertSame('succeeded', $this->db()->query("SELECT status FROM payments WHERE id = {$payment['id']}")->fetchColumn());
        $this->assertSame([['charge', 4797], ['platform_fee', 480]], $this->ledger($bookingId));
        $this->assertSame(['amount_pence' => 4317, 'status' => 'pending'], $this->db()->query("SELECT amount_pence, status FROM payouts WHERE booking_id = {$bookingId}")->fetch());
        $this->assertStringContainsString('Paid £47.97', $renter->get("/rentals/{$requestId}")->body);
        $this->assertStringContainsString('Held until the rental is completed', $owner->get("/rentals/{$requestId}")->body);

        // Stripe retries the same event, and also sends a second event for the same session: nothing doubles.
        $this->assertSame([200, 'duplicate'], FakeStripe::deliver(self::$app, $payload, $sig));
        $this->assertSame(200, $this->webhook('checkout.session.completed', $this->paidSession($payment))[0]);
        $this->assertSame([['charge', 4797], ['platform_fee', 480]], $this->ledger($bookingId));
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM booking_events WHERE booking_id = {$bookingId} AND to_status = 'confirmed'")->fetchColumn());
        $this->assertSame(1, (int) $this->db()->query("SELECT attempts FROM webhook_events WHERE event_id = '" . json_decode($payload, true)['id'] . "'")->fetchColumn() - 1);

        // Once paid, it cannot be paid again.
        $renter->post("/rentals/{$requestId}/pay");
        $this->assertStringContainsString('already paid', $renter->get("/rentals/{$requestId}")->body);
    }

    public function testOpenCheckoutIsReusedAndOnlyTheRenterCanPay(): void
    {
        [$renter, $owner, $requestId, $bookingId] = $this->approvedBooking();
        $before = count(self::$stripe->requests('/v1/checkout/sessions'));
        $first = $this->startCheckout($renter, $requestId);
        $second = $this->startCheckout($renter, $requestId);
        $this->assertSame($first['id'], $second['id']);
        $this->assertCount($before + 1, self::$stripe->requests('/v1/checkout/sessions'), 'Double click must not create a second session');

        $owner->post("/rentals/{$requestId}/pay");
        [$stranger] = $this->signedInUser('stranger', self::$app);
        $stranger->post("/rentals/{$requestId}/pay");
        $this->assertCount($before + 1, self::$stripe->requests('/v1/checkout/sessions'));
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM payments WHERE booking_id = {$bookingId}")->fetchColumn());
    }

    public function testAbandonedCheckoutLeavesBookingPayableAndOutOfOrderEventsAreIgnored(): void
    {
        [$renter, , $requestId, $bookingId] = $this->approvedBooking();
        $first = $this->startCheckout($renter, $requestId);
        $this->assertSame([200, 'expired'], $this->webhook('checkout.session.expired', ['id' => $first['checkout_session_id'], 'object' => 'checkout.session', 'status' => 'expired']));
        $this->assertSame('approved_awaiting_payment', $this->bookingStatus($bookingId), 'An abandoned checkout must not block or cancel the booking');

        $second = $this->startCheckout($renter, $requestId);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(200, $this->webhook('checkout.session.completed', $this->paidSession($second))[0]);
        // A late "expired" for the session that was actually paid changes nothing.
        $this->webhook('checkout.session.expired', ['id' => $second['checkout_session_id'], 'object' => 'checkout.session', 'status' => 'expired']);
        $this->assertSame('succeeded', $this->db()->query("SELECT status FROM payments WHERE id = {$second['id']}")->fetchColumn());
        $this->assertSame('confirmed', $this->bookingStatus($bookingId));
    }

    public function testPaymentAfterTheDeadlineIsRefundedAutomatically(): void
    {
        [$renter, , $requestId, $bookingId] = $this->approvedBooking();
        $payment = $this->startCheckout($renter, $requestId);
        $this->db()->exec("UPDATE bookings SET payment_due_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {$bookingId}");
        $this->bookings()->expireOverdue();

        $this->assertSame([200, 'late_refunded'], $this->webhook('checkout.session.completed', $this->paidSession($payment)));
        $this->assertSame('expired', $this->bookingStatus($bookingId));
        $refund = self::$stripe->requests('/v1/refunds');
        $this->assertSame('4797', end($refund)['params']['amount']);
        $this->assertSame('refunded', $this->db()->query("SELECT status FROM payments WHERE id = {$payment['id']}")->fetchColumn());
        $this->assertSame([['charge', 4797], ['platform_fee', 480], ['refund', -4797], ['platform_fee_reversal', -480]], $this->ledger($bookingId));
        $this->assertFalse($this->db()->query("SELECT id FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());
    }

    public function testWrongAmountIsNotConfirmed(): void
    {
        [$renter, , $requestId, $bookingId] = $this->approvedBooking();
        $payment = $this->startCheckout($renter, $requestId);
        $this->assertSame([200, 'amount_mismatch'], $this->webhook('checkout.session.completed', $this->paidSession($payment, ['amount_total' => 100])));
        $this->assertSame('approved_awaiting_payment', $this->bookingStatus($bookingId));
        $this->assertSame([], $this->ledger($bookingId));
    }

    public function testStaleOrWrongModeWebhooksAreRejected(): void
    {
        [$renter, , $requestId, $bookingId] = $this->approvedBooking();
        $payment = $this->startCheckout($renter, $requestId);
        [$payload] = FakeStripe::event('checkout.session.completed', $this->paidSession($payment));
        $this->assertSame(400, FakeStripe::deliver(self::$app, $payload, FakeStripe::sign($payload, time() - 600))[0], 'Replay of an old signed event');
        $this->assertSame([200, 'ignored: wrong mode'], $this->webhook('checkout.session.completed', $this->paidSession($payment), ['livemode' => true]));
        $this->assertSame('approved_awaiting_payment', $this->bookingStatus($bookingId));
    }

    public function testOwnerOnboardingAndPayoutWhenTheRentalIsCompleted(): void
    {
        [$renter, $owner, $requestId, $bookingId, $ownerId] = $this->approvedBooking();
        $this->assertStringContainsString('Set up payouts with Stripe', $owner->get('/payouts')->body);

        // Owner starts onboarding, returns before finishing.
        $owner->post('/payouts/onboard');
        $owner->get('/payouts/return');
        $this->assertStringContainsString('not finished yet', $owner->get('/payouts')->body);
        $account = $this->onboard($owner, $ownerId);
        $this->assertSame(['details_submitted' => 1, 'charges_enabled' => 1, 'payouts_enabled' => 1],
            array_map('intval', $this->db()->query("SELECT details_submitted, charges_enabled, payouts_enabled FROM connected_accounts WHERE user_id = {$ownerId}")->fetch()));
        $this->assertStringContainsString('Payouts are set up', $owner->get('/payouts')->body);
        $this->assertCount(1, self::$stripe->requests('/v1/accounts'), 'Second onboarding click reuses the account');

        $payment = $this->startCheckout($renter, $requestId);
        $this->webhook('checkout.session.completed', $this->paidSession($payment));

        $owner->post("/rentals/{$requestId}/status", ['to' => 'in_rental']);
        $this->assertSame([], self::$stripe->requests('/v1/transfers'), 'No payout before the rental is completed');
        $renter->post("/rentals/{$requestId}/status", ['to' => 'return_pending']);
        $owner->post("/rentals/{$requestId}/status", ['to' => 'completed']);

        $transfers = self::$stripe->requests('/v1/transfers');
        $this->assertCount(1, $transfers);
        $this->assertSame(['amount' => '4317', 'currency' => 'gbp', 'destination' => $account, 'transfer_group' => 'booking_' . $bookingId,
            'source_transaction' => 'ch_' . substr($payment['checkout_session_id'], 8)], array_diff_key($transfers[0]['params'], ['metadata' => 1]));
        $this->assertSame('transferred', $this->db()->query("SELECT status FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());
        $this->assertSame([['charge', 4797], ['platform_fee', 480], ['transfer', -4317]], $this->ledger($bookingId));
        $this->assertStringContainsString('Sent to your Stripe account', $owner->get('/payouts')->body);
    }

    public function testPayoutWaitsForTheOwnersAccountThenIsReleased(): void
    {
        [$renter, $owner, $requestId, $bookingId, $ownerId] = $this->approvedBooking();
        $payment = $this->startCheckout($renter, $requestId);
        $this->webhook('checkout.session.completed', $this->paidSession($payment));
        $owner->post("/rentals/{$requestId}/status", ['to' => 'in_rental']);
        $owner->post("/rentals/{$requestId}/status", ['to' => 'completed']);
        $this->assertSame('awaiting_owner_account', $this->db()->query("SELECT status FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());
        $this->assertStringContainsString('finish setting up payouts', $owner->get("/rentals/{$requestId}")->body);

        $before = count(self::$stripe->requests('/v1/transfers'));
        $this->onboard($owner, $ownerId); // account.updated → payouts enabled → waiting payouts released
        $this->assertCount($before + 1, self::$stripe->requests('/v1/transfers'));
        $this->assertSame('transferred', $this->db()->query("SELECT status FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());
    }

    public function testFailedTransferIsRetriedWithoutPayingTwice(): void
    {
        [$renter, $owner, $requestId, $bookingId, $ownerId] = $this->approvedBooking();
        $this->onboard($owner, $ownerId);
        $payment = $this->startCheckout($renter, $requestId);
        $this->webhook('checkout.session.completed', $this->paidSession($payment));
        self::$stripe->config(['fail_next_transfer' => '1']);
        $owner->post("/rentals/{$requestId}/status", ['to' => 'in_rental']);
        $owner->post("/rentals/{$requestId}/status", ['to' => 'completed']);
        $this->assertSame('failed', $this->db()->query("SELECT status FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());

        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $config['stripe'] = ['secret_key' => FakeStripe::SECRET_KEY, 'api_base' => self::$stripe->url] + $config['stripe'];
        $service = new \App\Payments\PaymentService($this->db(), new \App\Payments\StripeGateway($config['stripe']), $this->bookings(), $config);
        $this->assertSame([$bookingId => 'transferred'], $service->releaseDuePayouts($ownerId));
        $this->assertSame([], $service->releaseDuePayouts($ownerId), 'Nothing left to release');
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM payment_ledger WHERE booking_id = {$bookingId} AND entry_type = 'transfer'")->fetchColumn());
        $keys = array_column(self::$stripe->requests('/v1/transfers'), 'idempotency_key');
        $payoutId = (int) $this->db()->query("SELECT id FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn();
        $this->assertSame(["payout-{$payoutId}-attempt-1", "payout-{$payoutId}-attempt-2"], array_values(array_filter($keys, static fn ($k) => str_starts_with((string) $k, "payout-{$payoutId}-"))));
    }

    public function testOwnerCancellingAPaidBookingRefundsTheRenterInFull(): void
    {
        [$renter, $owner, $requestId, $bookingId] = $this->approvedBooking();
        $payment = $this->startCheckout($renter, $requestId);
        $this->webhook('checkout.session.completed', $this->paidSession($payment));

        $renter->post("/rentals/{$requestId}/owner-cancel");
        $this->assertSame('confirmed', $this->bookingStatus($bookingId), 'Renter cannot use the owner cancel');

        $owner->post("/rentals/{$requestId}/owner-cancel");
        $this->assertSame('cancelled', $this->bookingStatus($bookingId));
        $this->assertSame(['refunded', 4797], [$this->db()->query("SELECT status FROM payments WHERE id = {$payment['id']}")->fetchColumn(),
            (int) $this->db()->query("SELECT refunded_pence FROM payments WHERE id = {$payment['id']}")->fetchColumn()]);
        $this->assertSame(['amount_pence' => 0, 'status' => 'cancelled'], $this->db()->query("SELECT amount_pence, status FROM payouts WHERE booking_id = {$bookingId}")->fetch());
        $this->assertStringContainsString('Refund £47.97 — succeeded (Owner cancelled the booking)', $renter->get("/rentals/{$requestId}")->body);
        $this->assertSame([['charge', 4797], ['platform_fee', 480], ['refund', -4797], ['platform_fee_reversal', -480]], $this->ledger($bookingId));

        // A late, re-sent "payment completed" (new event ID) must not resurrect the refunded payment.
        $this->assertSame([200, 'already_recorded'], $this->webhook('checkout.session.completed', $this->paidSession($payment)));
        $this->assertSame('refunded', $this->db()->query("SELECT status FROM payments WHERE id = {$payment['id']}")->fetchColumn());
        $this->assertSame('cancelled', $this->bookingStatus($bookingId));
        $this->assertSame('cancelled', $this->db()->query("SELECT status FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());
    }

    public function testPartialRefundReducesThePayoutAndFailedRefundsAreRecorded(): void
    {
        [$renter, , $requestId, $bookingId] = $this->approvedBooking();
        $payment = $this->startCheckout($renter, $requestId);
        $this->webhook('checkout.session.completed', $this->paidSession($payment));
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $config['stripe'] = ['secret_key' => FakeStripe::SECRET_KEY, 'api_base' => self::$stripe->url] + $config['stripe'];
        $service = new \App\Payments\PaymentService($this->db(), new \App\Payments\StripeGateway($config['stripe']), $this->bookings(), $config);

        $service->refund((int) $payment['id'], 1599, 'One day not used', 'operator:test');
        $this->assertSame('partially_refunded', $this->db()->query("SELECT status FROM payments WHERE id = {$payment['id']}")->fetchColumn());
        // Owner keeps their 90% of the remaining £31.98 = £28.78; RentNReturn returns its 10% of the refund.
        $this->assertSame(2878, (int) $this->db()->query("SELECT amount_pence FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());
        $this->assertSame([['charge', 4797], ['platform_fee', 480], ['refund', -1599], ['platform_fee_reversal', -160]], $this->ledger($bookingId));

        try {
            $service->refund((int) $payment['id'], 5000, 'Too much', 'operator:test');
            $this->fail('Over-refund must be refused');
        } catch (\App\Payments\PaymentException $e) {
            $this->assertStringContainsString('more than the amount still refundable (£31.98)', $e->getMessage());
        }

        self::$stripe->config(['fail_next_refund' => '1']);
        try {
            $service->refund((int) $payment['id'], 100, 'Goodwill', 'operator:test');
            $this->fail('Stripe failure must surface');
        } catch (\App\Payments\PaymentException $e) {
            $this->assertStringContainsString('recorded and will be retried', $e->getMessage());
        }
        $this->assertSame(['status' => 'failed', 'amount_pence' => 100], $this->db()->query("SELECT status, amount_pence FROM refunds WHERE payment_id = {$payment['id']} ORDER BY id DESC LIMIT 1")->fetch());
        $this->assertSame(1599, (int) $this->db()->query("SELECT refunded_pence FROM payments WHERE id = {$payment['id']}")->fetchColumn(), 'A failed refund is not counted');
    }

    public function testPendingRefundIsCountedOnlyWhenStripeConfirmsIt(): void
    {
        [$renter, $owner, $requestId, $bookingId] = $this->approvedBooking();
        $payment = $this->startCheckout($renter, $requestId);
        $this->webhook('checkout.session.completed', $this->paidSession($payment));
        self::$stripe->config(['refund_status' => 'pending']);
        $owner->post("/rentals/{$requestId}/owner-cancel");
        self::$stripe->config(['refund_status' => 'succeeded']);
        $refundId = (string) $this->db()->query("SELECT stripe_refund_id FROM refunds WHERE payment_id = {$payment['id']}")->fetchColumn();
        $this->assertSame(0, (int) $this->db()->query("SELECT refunded_pence FROM payments WHERE id = {$payment['id']}")->fetchColumn());

        $this->assertSame([200, 'updated'], $this->webhook('refund.updated', ['id' => $refundId, 'object' => 'refund', 'status' => 'succeeded', 'amount' => 4797]));
        $this->webhook('refund.updated', ['id' => $refundId, 'object' => 'refund', 'status' => 'succeeded', 'amount' => 4797]);
        $this->assertSame(4797, (int) $this->db()->query("SELECT refunded_pence FROM payments WHERE id = {$payment['id']}")->fetchColumn());
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM payment_ledger WHERE booking_id = {$bookingId} AND entry_type = 'refund'")->fetchColumn());
    }

    public function testRenterPaysTheFeeWhenConfiguredThatWay(): void
    {
        $app = TestEnvironment::server(self::$stripe->appEnv(['PLATFORM_FEE_PAYER' => 'renter']));
        [$renter, $owner, $requestId, $bookingId] = $this->approvedBooking($app);
        $this->assertStringContainsString('Pay £52.77 securely', $renter->get("/rentals/{$requestId}")->body);
        $payment = $this->startCheckout($renter, $requestId);
        $sent = self::$stripe->requests('/v1/checkout/sessions');
        $items = end($sent)['params']['line_items'];
        $this->assertSame(['4797', '480'], [$items[0]['price_data']['unit_amount'], $items[1]['price_data']['unit_amount']]);
        $this->assertSame('RentNReturn service fee', $items[1]['price_data']['product_data']['name']);
        $this->webhook('checkout.session.completed', $this->paidSession($payment), [], $app);
        $this->assertSame(4797, (int) $this->db()->query("SELECT amount_pence FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn(), 'Owner receives the full rental price');
    }

    /** The same event delivered many times at once, plus a second event for the same payment: recorded once. */
    public function testConcurrentDuplicateWebhooksAreProcessedOnce(): void
    {
        $app = TestEnvironment::server(self::$stripe->appEnv(['PHP_CLI_SERVER_WORKERS' => '8']));
        [$renter, , $requestId, $bookingId] = $this->approvedBooking($app);
        $payment = $this->startCheckout($renter, $requestId);
        [$payload, $sig] = FakeStripe::event('checkout.session.completed', $this->paidSession($payment));
        [$payload2, $sig2] = FakeStripe::event('checkout.session.completed', $this->paidSession($payment));

        $handles = [];
        for ($i = 0; $i < 6; $i++) {
            $handles[] = FakeStripe::webhookHandle($app, $payload, $sig);
        }
        $handles[] = FakeStripe::webhookHandle($app, $payload2, $sig2);
        $multi = curl_multi_init();
        foreach ($handles as $h) {
            curl_multi_add_handle($multi, $h);
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running);
        $statuses = array_map(static fn ($h) => curl_getinfo($h, CURLINFO_RESPONSE_CODE), $handles);
        curl_multi_close($multi);

        foreach ($statuses as $s) {
            $this->assertContains($s, [200, 409], 'Each delivery is either handled, a duplicate, or told to retry');
        }
        $this->assertSame('confirmed', $this->bookingStatus($bookingId));
        $this->assertSame([['charge', 4797], ['platform_fee', 480]], $this->ledger($bookingId));
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM payouts WHERE booking_id = {$bookingId}")->fetchColumn());
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM booking_events WHERE booking_id = {$bookingId} AND to_status = 'confirmed'")->fetchColumn());
    }

    public function testPaymentsAreHiddenWhenStripeIsNotConfigured(): void
    {
        [$renter, , $requestId] = $this->approvedBooking(static::$url); // default test server: no Stripe keys
        $page = $renter->get("/rentals/{$requestId}")->body;
        $this->assertStringContainsString('Online payment is coming soon', $page);
        $this->assertStringNotContainsString('securely', $page);
        $this->assertSame(503, FakeStripe::deliver(static::$url, '{}', 't=1,v1=x')[0]);
    }
}
