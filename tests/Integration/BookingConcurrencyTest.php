<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\IntegrationTestCase;
use Tests\Support\TestEnvironment;

/** Step 2 gate: concurrent acceptance/payment cannot create overlapping or duplicate bookings. */
final class BookingConcurrencyTest extends IntegrationTestCase
{
    private function blockingBookings(int $listingId): int
    {
        return (int) $this->db()->query("SELECT COUNT(*) FROM bookings WHERE listing_id = {$listingId} AND status IN ('approved_awaiting_payment','confirmed','handover_ready','in_rental','return_pending')")->fetchColumn();
    }

    private function pendingRequest(int $listingId, int $renterId, string $start, string $end): int
    {
        $this->db()->prepare("INSERT INTO rental_requests (listing_id, renter_id, start_date, end_date, status, price_daily_pence, days, total_pence) VALUES (?, ?, ?, ?, 'pending', 1000, 3, 3000)")
            ->execute([$listingId, $renterId, $start, $end]);
        return (int) $this->db()->lastInsertId();
    }

    /**
     * Deterministic race: while another transaction holds the listing lock, an owner's "accept" must wait,
     * and once that other transaction commits a booking for the same dates, the waiting accept must see it
     * and refuse — rather than having checked availability before the lock and creating a second booking.
     */
    public function testAcceptWaitsForTheListingLockAndRechecksAfterIt(): void
    {
        [$owner, $ownerId] = $this->signedInUser('owner');
        $listingId = $this->createListing($ownerId);
        $aliceId = $this->createUser($this->uniqueEmail('alice'));
        $bobId = $this->createUser($this->uniqueEmail('bob'));
        $a = $this->pendingRequest($listingId, $aliceId, $this->day(10), $this->day(12));
        $b = $this->pendingRequest($listingId, $bobId, $this->day(11), $this->day(13));
        $accept = $owner->preparePost("/requests/{$b}/accept");

        // A competing transaction (e.g. another accept) takes the listing lock first...
        $other = TestEnvironment::pdo(true);
        $other->beginTransaction();
        $other->query("SELECT id FROM listings WHERE id = {$listingId} FOR UPDATE")->fetch();

        // ...the owner's accept for Bob starts and must block on that lock.
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $accept);
        $deadline = microtime(true) + 1.0;
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running && microtime(true) < $deadline);
        $this->assertSame(1, $running, 'Accept must wait while the listing is locked');

        // The competing transaction books Alice's overlapping dates and commits.
        $other->exec("UPDATE rental_requests SET status = 'accepted' WHERE id = {$a}");
        $other->exec("INSERT INTO bookings (rental_request_id, listing_id, renter_id, owner_id, start_date, end_date, status, price_daily_pence, days, total_pence, payment_due_at)
                      VALUES ({$a}, {$listingId}, {$aliceId}, {$ownerId}, '{$this->day(10)}', '{$this->day(12)}', 'approved_awaiting_payment', 1000, 3, 3000, UTC_TIMESTAMP() + INTERVAL 1 DAY)");
        $other->commit();

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running);
        curl_multi_remove_handle($multi, $accept);
        curl_multi_close($multi);

        $this->assertSame(1, $this->blockingBookings($listingId), 'Only the first booking may exist');
        $this->assertSame('pending', $this->db()->query("SELECT status FROM rental_requests WHERE id = {$b}")->fetchColumn());
        $this->assertStringContainsString('already been reserved', $owner->get('/dashboard')->body);
    }

    /** Burst: many overlapping requests accepted at the same instant on a multi-process server → exactly one booking. */
    public function testSimultaneousAcceptsCreateExactlyOneBooking(): void
    {
        $url = TestEnvironment::server(['PHP_CLI_SERVER_WORKERS' => '8']);
        [$owner, $ownerId] = $this->signedInUser('owner', $url);
        $listingId = $this->createListing($ownerId);
        $handles = [];
        for ($i = 0; $i < 8; $i++) {
            $renterId = $this->createUser($this->uniqueEmail('renter' . $i));
            $requestId = $this->pendingRequest($listingId, $renterId, $this->day(20 + $i % 2), $this->day(22));
            $handles[] = $owner->preparePost("/requests/{$requestId}/accept");
        }

        $multi = curl_multi_init();
        foreach ($handles as $h) {
            curl_multi_add_handle($multi, $h);
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
        } while ($running);
        foreach ($handles as $h) {
            $this->assertSame(302, curl_getinfo($h, CURLINFO_RESPONSE_CODE));
            curl_multi_remove_handle($multi, $h);
        }
        curl_multi_close($multi);

        $this->assertSame(1, $this->blockingBookings($listingId));
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM rental_requests WHERE listing_id = {$listingId} AND status = 'accepted'")->fetchColumn());
        $this->assertSame(7, (int) $this->db()->query("SELECT COUNT(*) FROM rental_requests WHERE listing_id = {$listingId} AND status = 'pending'")->fetchColumn());
    }

    /** Several payment notifications for the same booking processed at once → confirmed exactly once. */
    public function testSimultaneousPaymentConfirmationsConfirmOnce(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $renterId = $this->createUser($this->uniqueEmail('renter'));
        $listingId = $this->createListing($ownerId);
        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $svc = new \App\Booking\BookingService($this->db(), $config['bookings']);
        $requestId = $svc->requestRental($renterId, $listingId, $this->day(5), $this->day(6), null);
        $bookingId = $svc->approve($ownerId, $requestId);

        $db = TestEnvironment::dbConfig();
        $env = ['TEST_DB_HOST' => $db['host'], 'TEST_DB_PORT' => (string) $db['port'], 'TEST_DB_NAME' => $db['database'],
            'TEST_DB_USERNAME' => $db['username'], 'TEST_DB_PASSWORD' => $db['password'], 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
        $startAt = microtime(true) + 1.0;
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = proc_open([PHP_BINARY, dirname(__DIR__) . '/confirm_worker.php', (string) $bookingId, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            $outputs[$i] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $proc) {
            $results[] = trim(stream_get_contents($outputs[$i][1]));
            $err = stream_get_contents($outputs[$i][2]);
            proc_close($proc);
            $this->assertSame('', $err);
        }
        sort($results);
        $this->assertSame(['already_confirmed', 'already_confirmed', 'already_confirmed', 'already_confirmed', 'already_confirmed', 'confirmed'], $results);
        $this->assertSame(1, (int) $this->db()->query("SELECT COUNT(*) FROM booking_events WHERE booking_id = {$bookingId} AND to_status = 'confirmed'")->fetchColumn());
    }
}
