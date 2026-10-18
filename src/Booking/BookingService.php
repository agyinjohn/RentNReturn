<?php

declare(strict_types=1);

namespace App\Booking;

use App\Logger;
use App\Verification\TrustBadge;
use PDO;

/**
 * Every change to a rental request or booking goes through this class.
 *
 * Concurrency rule: each state change runs in a transaction that FIRST locks the listing row
 * (SELECT ... FOR UPDATE), then re-reads and re-checks the request/booking under that lock.
 * All changes for one item are therefore serialised, so two people can never both secure the same
 * dates, and because the listing is always locked first there is no lock-ordering deadlock.
 */
final class BookingService
{
    public function __construct(private PDO $pdo, private array $rules)
    {
    }

    // ---------------------------------------------------------------- requests

    /** Renter asks for dates. Returns the new request ID. Prices are computed here, never taken from the browser. */
    public function requestRental(int $renterId, int $listingId, string $start, string $end, ?string $message): int
    {
        $stmt = $this->pdo->prepare('SELECT id, user_id, published, price_daily_pence, available_from, available_until FROM listings WHERE id = ?');
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch();
        if (!$listing || !(int) $listing['published']) {
            throw new BookingException('Listing not found.');
        }
        if ((int) $listing['user_id'] === $renterId) {
            throw new BookingException('You cannot request to rent your own listing.');
        }
        $period = RentalPeriod::fromStrings($start, $end);
        if ($period === null) {
            throw new BookingException('Please choose a valid start and end date (the end date can be the same as the start date).');
        }
        if (($problem = $period->problemFor($listing, $this->rules)) !== null) {
            throw new BookingException($problem);
        }
        if ($this->conflictingBooking($listingId, $period->startString(), $period->endString(), 0, false) !== null) {
            throw new BookingException('Those dates are no longer available.');
        }
        $dup = $this->pdo->prepare("SELECT id FROM rental_requests WHERE listing_id = ? AND renter_id = ? AND status = 'pending' AND start_date <= ? AND end_date >= ? LIMIT 1");
        $dup->execute([$listingId, $renterId, $period->endString(), $period->startString()]);
        if ($dup->fetch()) {
            throw new BookingException('You already have a pending request for these dates.');
        }

        $price = (int) $listing['price_daily_pence'];
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('
                INSERT INTO rental_requests (listing_id, renter_id, start_date, end_date, message, status, price_daily_pence, days, total_pence)
                VALUES (?, ?, ?, ?, ?, \'pending\', ?, ?, ?)
            ')->execute([$listingId, $renterId, $period->startString(), $period->endString(), $message ?: null, $price, $period->days(), $period->totalPence($price)]);
            $requestId = (int) $this->pdo->lastInsertId();
            $this->event($requestId, null, $renterId, 'renter', null, 'requested');
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $requestId;
    }

    /** Owner approves a pending request: creates a booking awaiting payment, if the dates are still free. Returns the booking ID. */
    public function approve(int $ownerId, int $requestId): int
    {
        return $this->locked($this->listingIdForRequest($requestId), function (array $listing) use ($ownerId, $requestId): int {
            $req = $this->lockRequest($requestId);
            if (!$req || (int) $listing['user_id'] !== $ownerId || $req['status'] !== 'pending') {
                throw new BookingException('Request not found or already responded.');
            }
            if (!(int) $listing['published']) {
                throw new BookingException('Listing is no longer available.');
            }
            $period = RentalPeriod::fromStrings($req['start_date'], $req['end_date']);
            if ($period === null || $period->start < RentalPeriod::today()) {
                throw new BookingException('This request has already started or passed, so it can no longer be approved.');
            }
            if ((!empty($listing['available_from']) && $req['start_date'] < $listing['available_from'])
                || (!empty($listing['available_until']) && $req['end_date'] > $listing['available_until'])) {
                throw new BookingException('Requested dates are outside the listing availability window.');
            }
            if ($this->conflictingBooking((int) $listing['id'], $req['start_date'], $req['end_date'], 0, true) !== null) {
                throw new BookingException('Those dates have already been reserved by another booking.');
            }

            $due = $this->paymentDueAt($period);
            $this->pdo->prepare("UPDATE rental_requests SET status = 'accepted', responded_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$requestId]);
            $this->pdo->prepare('
                INSERT INTO bookings (rental_request_id, listing_id, renter_id, owner_id, start_date, end_date, status, price_daily_pence, days, total_pence, payment_due_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([$requestId, $listing['id'], $req['renter_id'], $ownerId, $req['start_date'], $req['end_date'], BookingStatus::AWAITING_PAYMENT,
                $req['price_daily_pence'], $req['days'], $req['total_pence'], $due]);
            $bookingId = (int) $this->pdo->lastInsertId();
            $this->event($requestId, $bookingId, $ownerId, 'owner', 'pending', BookingStatus::AWAITING_PAYMENT, 'Payment due by ' . $due . ' UTC');
            return $bookingId;
        });
    }

    public function decline(int $ownerId, int $requestId): void
    {
        $this->locked($this->listingIdForRequest($requestId), function (array $listing) use ($ownerId, $requestId): void {
            $req = $this->lockRequest($requestId);
            if (!$req || (int) $listing['user_id'] !== $ownerId || $req['status'] !== 'pending') {
                throw new BookingException('Request not found or already responded.');
            }
            $this->pdo->prepare("UPDATE rental_requests SET status = 'declined', responded_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$requestId]);
            $this->event($requestId, null, $ownerId, 'owner', 'pending', 'declined');
        });
    }

    /** Renter withdraws a request the owner has not answered yet. */
    public function cancelRequest(int $renterId, int $requestId): void
    {
        $this->locked($this->listingIdForRequest($requestId), function () use ($renterId, $requestId): void {
            $req = $this->lockRequest($requestId);
            if (!$req || (int) $req['renter_id'] !== $renterId || $req['status'] !== 'pending') {
                throw new BookingException('This request can no longer be cancelled.');
            }
            $this->pdo->prepare("UPDATE rental_requests SET status = 'cancelled', responded_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$requestId]);
            $this->event($requestId, null, $renterId, 'renter', 'pending', 'cancelled');
        });
    }

    // ---------------------------------------------------------------- bookings

    /** A renter or owner moves their booking along (cancel, handover, return...). Rules in BookingStatus. */
    public function transition(int $userId, int $requestId, string $to): void
    {
        $this->locked($this->listingIdForRequest($requestId), function () use ($userId, $requestId, $to): void {
            $booking = $this->lockBookingByRequest($requestId);
            $role = $booking ? $this->roleOf($booking, $userId) : null;
            if (!$booking || $role === null) {
                throw new BookingException('Booking not found.');
            }
            if ($booking['status'] === BookingStatus::AWAITING_PAYMENT && $this->isOverdue($booking)) {
                $this->expireLocked($booking);
                throw new BookingException('The payment deadline for this booking has passed, so it has expired.');
            }
            if (!BookingStatus::canTransition($booking['status'], $to, $role)) {
                throw new BookingException('That action is not available for this booking right now.');
            }
            $this->setBookingStatus($booking, $to, $userId, $role);
        });
    }

    /**
     * Mark a booking paid. Only to be called from a verified payment-provider event (Step 4: Stripe webhook),
     * never from a browser request. Safe to call repeatedly for the same booking.
     *
     * @return string confirmed | already_confirmed | expired | invalid
     */
    public function confirmPayment(int $bookingId, ?string $reference = null): string
    {
        $stmt = $this->pdo->prepare('SELECT listing_id FROM bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $listingId = $stmt->fetchColumn();
        if ($listingId === false) {
            return 'invalid';
        }
        return $this->locked((int) $listingId, function () use ($bookingId, $reference): string {
            $booking = $this->lockBooking($bookingId);
            if ($booking === null) {
                return 'invalid';
            }
            if ($booking['status'] === BookingStatus::CONFIRMED) {
                return 'already_confirmed';
            }
            if ($booking['status'] !== BookingStatus::AWAITING_PAYMENT) {
                return $booking['status'] === BookingStatus::EXPIRED ? 'expired' : 'invalid';
            }
            if ($this->isOverdue($booking)) {
                $this->expireLocked($booking);
                return 'expired';
            }
            // Defensive: approval already prevents overlaps, but never confirm two bookings for the same dates.
            if ($this->conflictingBooking((int) $booking['listing_id'], $booking['start_date'], $booking['end_date'], $bookingId, true) !== null) {
                Logger::error('Payment for a booking whose dates are taken', ['booking_id' => $bookingId]);
                return 'invalid';
            }
            $this->pdo->prepare('UPDATE bookings SET confirmed_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$bookingId]);
            $this->setBookingStatus($booking, BookingStatus::CONFIRMED, null, 'system', $reference ? 'Payment ' . $reference : 'Payment received');
            return 'confirmed';
        });
    }

    /**
     * Release approvals whose payment deadline passed and expire requests whose start date passed unanswered.
     * Runs hourly from bin/cleanup.php and opportunistically on booking pages. Overdue approvals never block
     * dates even before this runs (see conflictingBooking).
     *
     * @return array{bookings: int, requests: int}
     */
    public function expireOverdue(): array
    {
        $counts = ['bookings' => 0, 'requests' => 0];
        $stmt = $this->pdo->prepare('SELECT id, listing_id FROM bookings WHERE status = ? AND payment_due_at IS NOT NULL AND payment_due_at <= ?');
        $stmt->execute([BookingStatus::AWAITING_PAYMENT, gmdate('Y-m-d H:i:s')]);
        foreach ($stmt->fetchAll() as $row) {
            $counts['bookings'] += $this->locked((int) $row['listing_id'], function () use ($row): int {
                $booking = $this->lockBooking((int) $row['id']);
                if ($booking === null || $booking['status'] !== BookingStatus::AWAITING_PAYMENT || !$this->isOverdue($booking)) {
                    return 0;
                }
                $this->expireLocked($booking);
                return 1;
            });
        }

        $stmt = $this->pdo->prepare("SELECT id, listing_id FROM rental_requests WHERE status = 'pending' AND start_date < ?");
        $stmt->execute([RentalPeriod::today()->format('Y-m-d')]);
        foreach ($stmt->fetchAll() as $row) {
            $counts['requests'] += $this->locked((int) $row['listing_id'], function () use ($row): int {
                $req = $this->lockRequest((int) $row['id']);
                if ($req === null || $req['status'] !== 'pending' || $req['start_date'] >= RentalPeriod::today()->format('Y-m-d')) {
                    return 0;
                }
                $this->pdo->prepare("UPDATE rental_requests SET status = 'expired', responded_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$row['id']]);
                $this->event((int) $row['id'], null, null, 'system', 'pending', 'expired', 'Not answered before the start date');
                return 1;
            });
        }
        if ($counts['bookings'] + $counts['requests'] > 0) {
            Logger::info('Expired overdue rentals', $counts);
        }
        return $counts;
    }

    // ---------------------------------------------------------------- reading

    /**
     * A rental (request + booking if approved) as seen by $userId, or null if they are neither renter nor owner.
     * Adds 'role' (renter|owner) and 'status' (booking status, or request status before approval).
     */
    public function findForUser(int $requestId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(self::rentalSelect() . ' WHERE r.id = ?');
        $stmt->execute([$requestId]);
        $rental = $stmt->fetch();
        if (!$rental) {
            return null;
        }
        $rental['role'] = match ($userId) {
            (int) $rental['renter_id'] => 'renter',
            (int) $rental['owner_id'] => 'owner',
            default => null,
        };
        return $rental['role'] === null ? null : $rental;
    }

    /** @return array[] rentals where the user is the renter */
    public function listForRenter(int $userId): array
    {
        $stmt = $this->pdo->prepare(self::rentalSelect() . ' WHERE r.renter_id = ? ORDER BY r.created_at DESC, r.id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** @return array[] rentals for the user's listings */
    public function listForOwner(int $userId): array
    {
        $stmt = $this->pdo->prepare(self::rentalSelect() . ' WHERE l.user_id = ? ORDER BY r.created_at DESC, r.id DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /** @return array[] history entries, oldest first */
    public function timeline(int $requestId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT e.*, ' . TrustBadge::publicNameSql('u') . ' AS actor_name FROM booking_events e
            LEFT JOIN users u ON u.id = e.actor_user_id
            WHERE e.rental_request_id = ? ORDER BY e.created_at, e.id
        ');
        $stmt->execute([$requestId]);
        return $stmt->fetchAll();
    }

    /** Rental + booking + parties. Names and badges come from TrustBadge, the single definition of both. */
    private static function rentalSelect(): string
    {
        return '
        SELECT r.id, r.listing_id, r.renter_id, l.user_id AS owner_id, r.start_date, r.end_date, r.message, r.created_at,
               r.price_daily_pence, r.days, r.total_pence, r.status AS request_status,
               b.id AS booking_id, b.status AS booking_status, b.payment_due_at, b.confirmed_at,
               COALESCE(b.status, r.status) AS status,
               l.title AS listing_title, l.slug AS listing_slug,
               ' . TrustBadge::publicNameSql('renter') . ' AS renter_name, ' . TrustBadge::publicNameSql('owner') . ' AS owner_name,
               ' . TrustBadge::sql('renter') . ' AS renter_verified, ' . TrustBadge::sql('owner') . ' AS owner_verified
        FROM rental_requests r
        JOIN listings l ON l.id = r.listing_id
        JOIN users renter ON renter.id = r.renter_id
        JOIN users owner ON owner.id = l.user_id
        LEFT JOIN bookings b ON b.rental_request_id = r.id';
    }

    // ---------------------------------------------------------------- internals

    /**
     * ID of a booking that holds any of these dates for the listing, or null.
     * Approvals past their payment deadline do not hold dates. Inclusive dates: sharing a day is an overlap.
     */
    private function conflictingBooking(int $listingId, string $start, string $end, int $excludeBookingId, bool $forUpdate): ?int
    {
        $blocking = array_diff(BookingStatus::BLOCKING, [BookingStatus::AWAITING_PAYMENT]);
        $in = implode(',', array_fill(0, count($blocking), '?'));
        $stmt = $this->pdo->prepare("
            SELECT id FROM bookings
            WHERE listing_id = ? AND id <> ? AND start_date <= ? AND end_date >= ?
              AND (status IN ({$in}) OR (status = ? AND (payment_due_at IS NULL OR payment_due_at > ?)))
            LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute([$listingId, $excludeBookingId, $end, $start, ...array_values($blocking), BookingStatus::AWAITING_PAYMENT, gmdate('Y-m-d H:i:s')]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Run $fn inside a transaction holding the listing's row lock. $fn receives the locked listing row. */
    private function locked(int $listingId, callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, user_id, published, available_from, available_until FROM listings WHERE id = ? FOR UPDATE');
            $stmt->execute([$listingId]);
            $listing = $stmt->fetch();
            if (!$listing) {
                throw new BookingException('Listing not found.');
            }
            $result = $fn($listing);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function listingIdForRequest(int $requestId): int
    {
        $stmt = $this->pdo->prepare('SELECT listing_id FROM rental_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new BookingException('Request not found or already responded.');
        }
        return (int) $id;
    }

    private function lockRequest(int $requestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rental_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$requestId]);
        return $stmt->fetch() ?: null;
    }

    private function lockBooking(int $bookingId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
        $stmt->execute([$bookingId]);
        return $stmt->fetch() ?: null;
    }

    private function lockBookingByRequest(int $requestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bookings WHERE rental_request_id = ? FOR UPDATE');
        $stmt->execute([$requestId]);
        return $stmt->fetch() ?: null;
    }

    private function roleOf(array $booking, int $userId): ?string
    {
        return match ($userId) {
            (int) $booking['renter_id'] => 'renter',
            (int) $booking['owner_id'] => 'owner',
            default => null,
        };
    }

    private function isOverdue(array $booking): bool
    {
        return $booking['payment_due_at'] !== null && $booking['payment_due_at'] <= gmdate('Y-m-d H:i:s');
    }

    private function expireLocked(array $booking): void
    {
        $this->setBookingStatus($booking, BookingStatus::EXPIRED, null, 'system', 'Payment deadline passed');
    }

    private function setBookingStatus(array $booking, string $to, ?int $actorId, string $role, ?string $note = null): void
    {
        $this->pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$to, $booking['id']]);
        $this->event((int) $booking['rental_request_id'], (int) $booking['id'], $actorId, $role, $booking['status'], $to, $note);
    }

    /**
     * Payment deadline for a new approval: the configured window, but no later than the start of the rental's
     * first day (UK time), and never less than the minimum window so a same-day rental can still be paid.
     */
    private function paymentDueAt(RentalPeriod $period): string
    {
        $now = time();
        $due = min($now + $this->rules['payment_window_hours'] * 3600, $period->start->getTimestamp());
        return gmdate('Y-m-d H:i:s', max($due, $now + $this->rules['min_payment_window_minutes'] * 60));
    }

    private function event(int $requestId, ?int $bookingId, ?int $actorId, string $role, ?string $from, string $to, ?string $note = null): void
    {
        $this->pdo->prepare('
            INSERT INTO booking_events (rental_request_id, booking_id, actor_user_id, actor_role, from_status, to_status, note)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ')->execute([$requestId, $bookingId, $actorId, $role, $from, $to, $note]);
    }
}
