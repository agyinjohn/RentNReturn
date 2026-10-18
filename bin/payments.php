<?php

declare(strict_types=1);

/*
 * Operator tool for payments (until the admin area in Step 7):
 *   php bin/payments.php status  <bookingId>                    payments, refunds, payout and ledger for a booking
 *   php bin/payments.php refund  <bookingId> <pounds> "<reason>"  refund part or all of the booking's payment
 *   php bin/payments.php release-due                              pay owners for completed rentals (also hourly via cleanup)
 *   php bin/payments.php webhooks                                 recent webhook events that failed
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/bootstrap.php';
if ($config['stripe']['secret_key'] === '') {
    fwrite(STDERR, "Stripe is not configured (STRIPE_SECRET_KEY).\n");
    exit(1);
}
$pdo = App\Database::get($config);
$payments = new App\Payments\PaymentService($pdo, new App\Payments\StripeGateway($config['stripe']), new App\Booking\BookingService($pdo, $config['bookings']), $config);
[, $command, $a, $b, $c] = $argv + [null, null, null, null, null];
$money = static fn ($p) => App\Booking\Money::format((int) $p);

switch ($command) {
    case 'status':
        $bookingId = (int) $a;
        foreach ($pdo->query("SELECT id, status, amount_pence, platform_fee_pence, owner_amount_pence, refunded_pence, checkout_session_id, payment_intent_id, paid_at FROM payments WHERE booking_id = {$bookingId} ORDER BY id") as $p) {
            printf("payment #%d %-18s charged %s fee %s owner %s refunded %s  %s %s\n", $p['id'], $p['status'], $money($p['amount_pence']), $money($p['platform_fee_pence']),
                $money($p['owner_amount_pence']), $money($p['refunded_pence']), $p['payment_intent_id'] ?? $p['checkout_session_id'], $p['paid_at'] ?? '');
        }
        foreach ($pdo->query("SELECT id, amount_pence, status, reason, actor, stripe_refund_id FROM refunds WHERE booking_id = {$bookingId} ORDER BY id") as $r) {
            printf("refund  #%d %-10s %s by %s: %s %s\n", $r['id'], $r['status'], $money($r['amount_pence']), $r['actor'], $r['reason'], $r['stripe_refund_id'] ?? '');
        }
        foreach ($pdo->query("SELECT id, amount_pence, status, stripe_transfer_id, note FROM payouts WHERE booking_id = {$bookingId}") as $o) {
            printf("payout  #%d %-22s %s %s %s\n", $o['id'], $o['status'], $money($o['amount_pence']), $o['stripe_transfer_id'] ?? '', $o['note'] ?? '');
        }
        echo "ledger:\n";
        foreach ($pdo->query("SELECT created_at, entry_type, amount_pence, provider_reference, actor, reason FROM payment_ledger WHERE booking_id = {$bookingId} ORDER BY id") as $l) {
            printf("  %s %-22s %10s  %-28s %s %s\n", $l['created_at'], $l['entry_type'], $money(abs((int) $l['amount_pence'])) . ((int) $l['amount_pence'] < 0 ? ' out' : ' in'), $l['provider_reference'] ?? '', $l['actor'], $l['reason'] ?? '');
        }
        break;

    case 'refund':
        $pence = App\Booking\Money::parsePounds((string) $b);
        if ($pence === null || trim((string) $c) === '') {
            fwrite(STDERR, "Usage: php bin/payments.php refund <bookingId> <pounds> \"<reason>\"\n");
            exit(1);
        }
        $stmt = $pdo->prepare("SELECT id FROM payments WHERE booking_id = ? AND status IN ('succeeded', 'partially_refunded') ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int) $a]);
        $paymentId = $stmt->fetchColumn();
        if ($paymentId === false) {
            fwrite(STDERR, "No refundable payment for booking {$a}.\n");
            exit(1);
        }
        try {
            $refundId = $payments->refund((int) $paymentId, $pence, (string) $c, 'operator:' . (getenv('USER') ?: 'cli'));
        } catch (App\Payments\PaymentException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
        echo "Refund #{$refundId} of {$money($pence)} submitted.\n";
        break;

    case 'release-due':
        foreach ($payments->releaseDuePayouts() as $bookingId => $result) {
            echo "booking {$bookingId}: {$result}\n";
        }
        break;

    case 'webhooks':
        foreach ($pdo->query("SELECT received_at, event_type, event_id, status, attempts, error FROM webhook_events WHERE status IN ('failed', 'processing') ORDER BY id DESC LIMIT 50") as $w) {
            printf("%s %-36s %s %s x%d %s\n", $w['received_at'], $w['event_type'], $w['event_id'], $w['status'], $w['attempts'], $w['error'] ?? '');
        }
        break;

    default:
        fwrite(STDERR, "Usage: php bin/payments.php status <bookingId> | refund <bookingId> <pounds> \"<reason>\" | release-due | webhooks\n");
        exit(1);
}
