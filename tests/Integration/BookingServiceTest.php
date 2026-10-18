<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Booking\BookingException;
use App\Booking\BookingService;
use App\Booking\RentalPeriod;
use PDOException;
use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Booking rules at the service/database level: payment confirmation, expiry, deadlines, constraints. */
final class BookingServiceTest extends IntegrationTestCase
{
    private function service(array $overrides = []): BookingService
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        return new BookingService(TestEnvironment::pdo(), $overrides + $config['bookings']);
    }

    /** Owner, renter, listing and an approved booking for the given dates. Returns [requestId, bookingId, ownerId, renterId, listingId]. */
    private function approvedBooking(int $startOffset = 10, int $endOffset = 12, array $rules = []): array
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $renterId = $this->createUser($this->uniqueEmail('renter'));
        $listingId = $this->createListing($ownerId, 2500);
        $svc = $this->service($rules);
        $requestId = $svc->requestRental($renterId, $listingId, $this->day($startOffset), $this->day($endOffset), null);
        $bookingId = $svc->approve($ownerId, $requestId);
        return [$requestId, $bookingId, $ownerId, $renterId, $listingId];
    }

    private function bookingStatus(int $bookingId): string
    {
        return (string) $this->db()->query("SELECT status FROM bookings WHERE id = {$bookingId}")->fetchColumn();
    }

    public function testBookingSnapshotsTheRequestPrice(): void
    {
        [, $bookingId, , , $listingId] = $this->approvedBooking(10, 13);
        // A later price change does not alter an existing booking.
        $this->db()->exec("UPDATE listings SET price_daily_pence = 9999 WHERE id = {$listingId}");
        $row = $this->db()->query("SELECT price_daily_pence, days, total_pence FROM bookings WHERE id = {$bookingId}")->fetch();
        $this->assertSame(['price_daily_pence' => 2500, 'days' => 4, 'total_pence' => 10000], array_map('intval', $row));
    }

    public function testConfirmPaymentIsIdempotent(): void
    {
        [$requestId, $bookingId] = $this->approvedBooking();
        $svc = $this->service();
        $this->assertSame('confirmed', $svc->confirmPayment($bookingId, 'pi_1'));
        $this->assertSame('already_confirmed', $svc->confirmPayment($bookingId, 'pi_1'));
        $this->assertSame('already_confirmed', $svc->confirmPayment($bookingId, 'pi_1'));
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM booking_events WHERE rental_request_id = {$requestId} AND to_status = 'confirmed'")->fetchColumn());
        $this->assertNotNull($this->db()->query("SELECT confirmed_at FROM bookings WHERE id = {$bookingId}")->fetchColumn());
        $this->assertSame('invalid', $svc->confirmPayment(999999));
    }

    public function testPaymentAfterTheDeadlineDoesNotConfirm(): void
    {
        [, $bookingId] = $this->approvedBooking();
        $this->db()->exec("UPDATE bookings SET payment_due_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = {$bookingId}");
        $this->assertSame('expired', $this->service()->confirmPayment($bookingId));
        $this->assertSame('expired', $this->bookingStatus($bookingId));
        $this->assertSame('expired', $this->service()->confirmPayment($bookingId), 'Still expired on retry');
    }

    public function testCancelledBookingCannotBePaid(): void
    {
        [$requestId, $bookingId, , $renterId] = $this->approvedBooking();
        $this->service()->transition($renterId, $requestId, 'cancelled');
        $this->assertSame('invalid', $this->service()->confirmPayment($bookingId));
        $this->assertSame('cancelled', $this->bookingStatus($bookingId));
    }

    public function testPaymentDeadlineIsCappedAtTheStartOfTheRental(): void
    {
        // Starts tomorrow: deadline is the start of tomorrow (UK) if that is sooner than 24h.
        [, $bookingId] = $this->approvedBooking(1, 2);
        $due = strtotime($this->db()->query("SELECT payment_due_at FROM bookings WHERE id = {$bookingId}")->fetchColumn() . ' UTC');
        $startOfTomorrow = RentalPeriod::today()->modify('+1 day')->getTimestamp();
        $this->assertLessThanOrEqual(max($startOfTomorrow, time() + 3600) + 1, $due);
        $this->assertLessThanOrEqual(time() + 24 * 3600 + 1, $due);

        // Same-day rental: never less than the 60-minute minimum.
        [, $sameDay] = $this->approvedBooking(0, 0);
        $due = strtotime($this->db()->query("SELECT payment_due_at FROM bookings WHERE id = {$sameDay}")->fetchColumn() . ' UTC');
        $this->assertEqualsWithDelta(time() + 3600, $due, 5);
    }

    public function testSweeperExpiresOverdueApprovalsAndStaleRequests(): void
    {
        [$requestId, $bookingId, $ownerId, $renterId, $listingId] = $this->approvedBooking();
        $this->db()->exec("UPDATE bookings SET payment_due_at = UTC_TIMESTAMP() - INTERVAL 5 MINUTE WHERE id = {$bookingId}");
        // A pending request whose start date has passed without an answer.
        $this->db()->prepare("INSERT INTO rental_requests (listing_id, renter_id, start_date, end_date, status, price_daily_pence, days, total_pence) VALUES (?, ?, ?, ?, 'pending', 2500, 2, 5000)")
            ->execute([$listingId, $renterId, $this->day(-2), $this->day(-1)]);
        $stale = (int) $this->db()->lastInsertId();

        $before = $this->service()->expireOverdue();
        $this->assertGreaterThanOrEqual(1, $before['bookings']);
        $this->assertGreaterThanOrEqual(1, $before['requests']);
        $this->assertSame('expired', $this->bookingStatus($bookingId));
        $this->assertSame('expired', $this->db()->query("SELECT status FROM rental_requests WHERE id = {$stale}")->fetchColumn());
        $events = $this->db()->query("SELECT actor_role, to_status, note FROM booking_events WHERE rental_request_id = {$requestId} ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertSame(['actor_role' => 'system', 'to_status' => 'expired', 'note' => 'Payment deadline passed'], $events);
        $this->assertSame(['bookings' => 0, 'requests' => 0], $this->service()->expireOverdue(), 'Nothing left to expire');
    }

    public function testLegacyApprovalsWithoutDeadlineNeverExpire(): void
    {
        [, $bookingId] = $this->approvedBooking();
        $this->db()->exec("UPDATE bookings SET payment_due_at = NULL WHERE id = {$bookingId}");
        $this->service()->expireOverdue();
        $this->assertSame('approved_awaiting_payment', $this->bookingStatus($bookingId));
    }

    public function testApprovingARequestThatAlreadyStartedIsRefused(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $renterId = $this->createUser($this->uniqueEmail('renter'));
        $listingId = $this->createListing($ownerId);
        $this->db()->prepare("INSERT INTO rental_requests (listing_id, renter_id, start_date, end_date, status, price_daily_pence, days, total_pence) VALUES (?, ?, ?, ?, 'pending', 1000, 3, 3000)")
            ->execute([$listingId, $renterId, $this->day(-1), $this->day(1)]);
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('already started');
        $this->service()->approve($ownerId, (int) $this->db()->lastInsertId());
    }

    public function testApprovalRechecksAvailabilityAndPublishedState(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $renterId = $this->createUser($this->uniqueEmail('renter'));
        $listingId = $this->createListing($ownerId);
        $svc = $this->service();
        $requestId = $svc->requestRental($renterId, $listingId, $this->day(10), $this->day(12), null);

        $this->db()->exec("UPDATE listings SET published = 0 WHERE id = {$listingId}");
        try {
            $svc->approve($ownerId, $requestId);
            $this->fail('Approving on an unpublished listing must fail');
        } catch (BookingException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }
        $this->db()->exec("UPDATE listings SET published = 1, available_now = 0, available_from = '{$this->day(11)}', available_until = '{$this->day(20)}' WHERE id = {$listingId}");
        $this->expectExceptionMessage('outside the listing availability window');
        $svc->approve($ownerId, $requestId);
    }

    public function testDatabaseConstraintsRejectBadRows(): void
    {
        $hasChecks = (int) TestEnvironment::serverPdo()->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'information_schema' AND table_name = 'CHECK_CONSTRAINTS'")->fetchColumn();
        if ($hasChecks === 0) {
            $this->markTestSkipped('This database server does not enforce CHECK constraints (MySQL < 8.0.16).');
        }
        [$requestId, $bookingId, $ownerId, $renterId, $listingId] = $this->approvedBooking();
        $bad = [
            "UPDATE listings SET price_daily_pence = 0 WHERE id = {$listingId}",
            "UPDATE listings SET available_from = '2030-05-10', available_until = '2030-05-01' WHERE id = {$listingId}",
            "UPDATE rental_requests SET end_date = start_date - INTERVAL 1 DAY WHERE id = {$requestId}",
            "UPDATE rental_requests SET status = 'approved' WHERE id = {$requestId}",
            "UPDATE rental_requests SET total_pence = 1 WHERE id = {$requestId}",
            "UPDATE bookings SET end_date = start_date - INTERVAL 1 DAY WHERE id = {$bookingId}",
            "UPDATE bookings SET status = 'paid' WHERE id = {$bookingId}",
            "UPDATE bookings SET total_pence = total_pence + 1 WHERE id = {$bookingId}",
        ];
        foreach ($bad as $sql) {
            try {
                $this->db()->exec($sql);
                $this->fail('Constraint did not reject: ' . $sql);
            } catch (PDOException $e) {
                $this->assertStringContainsString('constraint', strtolower($e->getMessage()), $sql);
            }
        }
    }
}
