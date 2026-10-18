<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Booking\BookingService;
use Tests\Support\Browser;
use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 2 gate, through the real app: dates, server-side pricing, overlaps, lifecycle and shared history. */
final class BookingFlowTest extends IntegrationTestCase
{
    private function service(): BookingService
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        return new BookingService(TestEnvironment::pdo(), $config['bookings']);
    }

    /** Renter requests dates; returns the new rental (request) ID, or null if refused. */
    private function request(Browser $b, int $listingId, string $start, string $end, array $extra = []): ?int
    {
        $r = $b->post("/listings/{$listingId}/request", ['start_date' => $start, 'end_date' => $end] + $extra);
        return preg_match('#^/rentals/(\d+)$#', (string) parse_url((string) $r->location(), PHP_URL_PATH), $m) ? (int) $m[1] : null;
    }

    private function requestError(Browser $b, int $listingId, string $start, string $end): string
    {
        $this->assertNull($this->request($b, $listingId, $start, $end), "{$start} → {$end} should be refused");
        $slug = $this->db()->query("SELECT slug FROM listings WHERE id = {$listingId}")->fetchColumn();
        preg_match('/class="form-error">([^<]+)</', $b->get('/listings/' . $slug)->body, $m);
        return html_entity_decode($m[1] ?? '');
    }

    private function dashboardMessage(Browser $owner): string
    {
        preg_match('/class="form-(?:error|success)">([^<]+)</', $owner->get('/dashboard')->body, $m);
        return html_entity_decode($m[1] ?? '');
    }

    private function rentalStatus(int $requestId): string
    {
        return (string) $this->db()->query("SELECT COALESCE(b.status, r.status) FROM rental_requests r LEFT JOIN bookings b ON b.rental_request_id = r.id WHERE r.id = {$requestId}")->fetchColumn();
    }

    private function blockingBookings(int $listingId): int
    {
        return (int) $this->db()->query("SELECT COUNT(*) FROM bookings WHERE listing_id = {$listingId} AND status IN ('approved_awaiting_payment','confirmed','handover_ready','in_rental','return_pending')")->fetchColumn();
    }

    public function testPriceIsCalculatedOnTheServerAndTamperedTotalsAreIgnored(): void
    {
        [, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId, 1599);
        [$renter] = $this->signedInUser('renter');

        $id = $this->request($renter, $listingId, $this->day(3), $this->day(5), ['total_pence' => '1', 'price_daily_pence' => '1', 'days' => '99', 'total' => '0.01']);
        $this->assertNotNull($id);
        $row = $this->db()->query("SELECT price_daily_pence, days, total_pence FROM rental_requests WHERE id = {$id}")->fetch();
        $this->assertSame(['price_daily_pence' => 1599, 'days' => 3, 'total_pence' => 4797], array_map('intval', $row));

        $page = $renter->get("/rentals/{$id}")->body;
        $this->assertStringContainsString('3 days, £47.97 in total', $page);
        $this->assertStringContainsString('£15.99/day × 3 = <strong>£47.97</strong>', $page);
    }

    public function testPastOutOfWindowAndInvalidDatesAreRefused(): void
    {
        [, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId, 1000, $this->day(5), $this->day(10));
        [$renter] = $this->signedInUser('renter');

        $this->assertStringContainsString('cannot be in the past', $this->requestError($renter, $this->createListing($ownerId), $this->day(-1), $this->day(2)));
        $this->assertStringContainsString('not available that early', $this->requestError($renter, $listingId, $this->day(4), $this->day(6)));
        $this->assertStringContainsString('not available that late', $this->requestError($renter, $listingId, $this->day(9), $this->day(11)));
        $this->assertStringContainsString('valid start and end date', $this->requestError($renter, $listingId, $this->day(8), $this->day(6)));
        $this->assertStringContainsString('valid start and end date', $this->requestError($renter, $listingId, '2030-02-30', '2030-03-01'));
        $open = $this->createListing($ownerId);
        $this->assertStringContainsString('at most 60 days', $this->requestError($renter, $open, $this->day(1), $this->day(61)));
        $this->assertStringContainsString('days ahead', $this->requestError($renter, $open, $this->day(366), $this->day(367)));
        $this->assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM rental_requests WHERE listing_id IN ({$listingId}, {$open})")->fetchColumn());

        // Boundaries are allowed: today, and exactly the availability window.
        $this->assertNotNull($this->request($renter, $open, $this->day(0), $this->day(0)));
        $this->assertNotNull($this->request($renter, $listingId, $this->day(5), $this->day(10)));
    }

    public function testTwoRentersSameDatesOnlyOneCanBeApproved(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId);
        [$alice] = $this->signedInUser('alice');
        [$bob] = $this->signedInUser('bob');

        // Both can ask (a request does not hold the dates)...
        $a = $this->request($alice, $listingId, $this->day(10), $this->day(12));
        $b = $this->request($bob, $listingId, $this->day(11), $this->day(13));
        $this->assertNotNull($a);
        $this->assertNotNull($b);

        // ...but only one can be approved.
        $owner->post("/requests/{$a}/accept");
        $this->assertStringContainsString('Request approved', $this->dashboardMessage($owner));
        $owner->post("/requests/{$b}/accept");
        $this->assertStringContainsString('already been reserved', $this->dashboardMessage($owner));
        $this->assertSame('approved_awaiting_payment', $this->rentalStatus($a));
        $this->assertSame('pending', $this->rentalStatus($b));
        $this->assertSame(1, $this->blockingBookings($listingId));

        // New requests for those dates are refused while Alice's approval holds them.
        [$carol] = $this->signedInUser('carol');
        $this->assertStringContainsString('no longer available', $this->requestError($carol, $listingId, $this->day(12), $this->day(14)));

        // Alice cancels: the dates are free and Bob can now be approved.
        $alice->post("/rentals/{$a}/status", ['to' => 'cancelled']);
        $this->assertSame('cancelled', $this->rentalStatus($a));
        $owner->post("/requests/{$b}/accept");
        $this->assertStringContainsString('Request approved', $this->dashboardMessage($owner));
        $this->assertSame(1, $this->blockingBookings($listingId));
    }

    public function testSharingAnEndDateIsAnOverlapButTheNextDayIsFree(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId);
        [$alice] = $this->signedInUser('alice');
        [$bob] = $this->signedInUser('bob');
        $a = $this->request($alice, $listingId, $this->day(3), $this->day(5));
        $owner->post("/requests/{$a}/accept");

        $this->assertStringContainsString('no longer available', $this->requestError($bob, $listingId, $this->day(5), $this->day(7)));
        $b = $this->request($bob, $listingId, $this->day(6), $this->day(8));
        $owner->post("/requests/{$b}/accept");
        $this->assertSame('approved_awaiting_payment', $this->rentalStatus($b));
        $this->assertSame(2, $this->blockingBookings($listingId));
    }

    public function testUnpaidApprovalExpiresAndReleasesTheDates(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId);
        [$alice] = $this->signedInUser('alice');
        [$bob] = $this->signedInUser('bob');
        $a = $this->request($alice, $listingId, $this->day(10), $this->day(12));
        $owner->post("/requests/{$a}/accept");

        $due = $this->db()->query("SELECT payment_due_at FROM bookings WHERE rental_request_id = {$a}")->fetchColumn();
        $this->assertNotNull($due);
        $hours = (strtotime($due . ' UTC') - time()) / 3600;
        $this->assertEqualsWithDelta(24, $hours, 0.1, 'Default payment window is 24 hours');

        // Deadline passes. Even before the hourly cleanup runs, the dates are no longer held.
        $this->db()->exec("UPDATE bookings SET payment_due_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE rental_request_id = {$a}");
        $b = $this->request($bob, $listingId, $this->day(10), $this->day(12));
        $this->assertNotNull($b, 'Overdue approval must not block new requests');
        $owner->post("/requests/{$b}/accept");
        $this->assertSame('approved_awaiting_payment', $this->rentalStatus($b));
        $this->assertSame('approved_awaiting_payment', $this->rentalStatus($a), 'Not yet swept');
        $owner->get('/dashboard');
        $this->assertSame('expired', $this->rentalStatus($a), 'Viewing the dashboard expires overdue approvals');

        $page = $alice->get("/rentals/{$a}")->body;
        $this->assertStringContainsString('Expired', $page);
        $alice->post("/rentals/{$a}/status", ['to' => 'cancelled']);
        $this->assertSame('expired', $this->rentalStatus($a), 'An expired booking cannot be revived');
    }

    public function testRenterAndOwnerShareTheBookingPageAndHistoryOthersCannotSeeIt(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId);
        [$renter] = $this->signedInUser('renter');
        $id = $this->request($renter, $listingId, $this->day(2), $this->day(3), ['message' => 'Need it for a fence']);
        $owner->post("/requests/{$id}/accept");

        foreach ([$renter, $owner] as $who) {
            $page = $who->get("/rentals/{$id}");
            $this->assertSame(200, $page->status);
            $this->assertStringContainsString('Approved — awaiting payment', $page->body);
            $this->assertMatchesRegularExpression('/Requested by Renter \w+ \(renter\)/', $page->body);
            $this->assertMatchesRegularExpression('/Approved by Owner \w+ \(owner\) — awaiting payment/', $page->body);
            $this->assertStringContainsString('Payment due by', $page->body);
            $this->assertStringContainsString('Need it for a fence', $page->body);
        }
        [$stranger] = $this->signedInUser('stranger');
        $this->assertSame(404, $stranger->get("/rentals/{$id}")->status);
        $this->assertSame(404, $stranger->get('/rentals/999999')->status);
        $this->assertRedirectsTo('/login', $this->browser()->get("/rentals/{$id}"));

        // Strangers cannot act on it either.
        $stranger->post("/rentals/{$id}/status", ['to' => 'cancelled']);
        $stranger->post("/requests/{$id}/decline");
        $this->assertSame('approved_awaiting_payment', $this->rentalStatus($id));
    }

    public function testRequestPhaseActions(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId);
        [$renter, $renterId] = $this->signedInUser('renter');

        // The form is hidden on your own listing; a forged POST is refused.
        $this->assertNull($this->request($owner, $listingId, $this->day(2), $this->day(3)));
        $this->assertSame(0, (int) $this->db()->query("SELECT COUNT(*) FROM rental_requests WHERE listing_id = {$listingId}")->fetchColumn());
        $first = $this->request($renter, $listingId, $this->day(2), $this->day(4));
        $this->assertStringContainsString('already have a pending request', $this->requestError($renter, $listingId, $this->day(3), $this->day(5)));

        // The owner cannot use the renter's cancel; the renter can.
        $owner->post("/rentals/{$first}/cancel-request");
        $this->assertSame('pending', $this->rentalStatus($first));
        $renter->post("/rentals/{$first}/cancel-request");
        $this->assertSame('cancelled', $this->rentalStatus($first));

        // A renter cannot approve or decline requests on someone else's listing.
        $second = $this->request($renter, $listingId, $this->day(6), $this->day(7));
        $renter->post("/requests/{$second}/accept");
        $this->assertSame('pending', $this->rentalStatus($second));
        $owner->post("/requests/{$second}/decline");
        $this->assertSame('declined', $this->rentalStatus($second));
        $this->assertStringContainsString('Declined by Owner', $renter->get("/rentals/{$second}")->body);

        $list = $renter->get('/requests')->body;
        $this->assertStringContainsString('Cancelled', $list);
        $this->assertStringContainsString('Declined by the owner', $list);
    }

    public function testFullLifecycleWithRoleChecks(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId);
        [$renter] = $this->signedInUser('renter');
        $id = $this->request($renter, $listingId, $this->day(1), $this->day(2));
        $owner->post("/requests/{$id}/accept");

        // Nobody can mark a booking paid from the browser — not even by forging the form.
        $renter->post("/rentals/{$id}/status", ['to' => 'confirmed']);
        $owner->post("/rentals/{$id}/status", ['to' => 'confirmed']);
        $this->assertSame('approved_awaiting_payment', $this->rentalStatus($id));

        // Payment arrives (Step 4 will call this from a verified Stripe webhook).
        $bookingId = (int) $this->db()->query("SELECT id FROM bookings WHERE rental_request_id = {$id}")->fetchColumn();
        $this->assertSame('confirmed', $this->service()->confirmPayment($bookingId, 'pi_test_123'));

        $steps = [
            [$renter, 'handover_ready', false], // owner-only
            [$owner, 'handover_ready', true],
            [$owner, 'return_pending', false], // renter-only
            [$renter, 'in_rental', true],
            [$owner, 'return_pending', false],
            [$renter, 'return_pending', true],
            [$renter, 'completed', false], // owner confirms the return
            [$owner, 'completed', true],
            [$owner, 'in_rental', false], // no way back
        ];
        foreach ($steps as $i => [$who, $to, $allowed]) {
            $before = $this->rentalStatus($id);
            $who->post("/rentals/{$id}/status", ['to' => $to]);
            $this->assertSame($allowed ? $to : $before, $this->rentalStatus($id), "step {$i}: {$to}");
        }

        $history = $owner->get("/rentals/{$id}")->body;
        foreach (['Requested by', 'Approved by', 'Payment received — booking confirmed', 'Ready for collection', 'In rental', 'Returned — awaiting owner check', 'Completed'] as $entry) {
            $this->assertStringContainsString($entry, $history);
        }
        $this->assertSame(7, (int) $this->db()->query("SELECT COUNT(*) FROM booking_events WHERE rental_request_id = {$id}")->fetchColumn());
    }

    public function testDashboardGroupsRentals(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId, 1000, null, null, 'Ladder 3m');
        [$renter] = $this->signedInUser('renter');
        $a = $this->request($renter, $listingId, $this->day(1), $this->day(2));
        $this->request($renter, $listingId, $this->day(5), $this->day(6));
        $owner->post("/requests/{$a}/accept");

        $page = $owner->get('/dashboard')->body;
        $this->assertMatchesRegularExpression('/Rental requests \(pending\).*Ladder 3m.*Requested — waiting.*Current bookings.*Ladder 3m.*Approved — awaiting payment/s', $page);
        $this->assertStringContainsString('£20.00', $page);
    }
}
