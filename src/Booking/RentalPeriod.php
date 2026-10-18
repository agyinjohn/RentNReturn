<?php

declare(strict_types=1);

namespace App\Booking;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The single definition of a rental period and how it is counted and priced.
 *
 * Dates are UK calendar dates (Europe/London), inclusive of both ends:
 *   - Mon → Wed is 3 rental days; a same-day rental (start = end) is 1 day.
 *   - The end date is a rental day, so back-to-back bookings cannot share a day
 *     (one ending on the 5th and another starting on the 5th overlap).
 */
final class RentalPeriod
{
    private function __construct(public readonly DateTimeImmutable $start, public readonly DateTimeImmutable $end)
    {
    }

    /** Parse strict Y-m-d dates. Returns null if either is malformed/impossible or start is after end. */
    public static function fromStrings(string $start, string $end): ?self
    {
        $tz = new DateTimeZone('Europe/London');
        $s = DateTimeImmutable::createFromFormat('!Y-m-d', $start, $tz);
        $e = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $tz);
        if (!$s || !$e || $s->format('Y-m-d') !== $start || $e->format('Y-m-d') !== $end || $s > $e) {
            return null;
        }
        return new self($s, $e);
    }

    /** Today's date in the UK, regardless of the server's timezone. */
    public static function today(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Europe/London'))->setTime(0, 0);
    }

    public function days(): int
    {
        return (int) $this->start->diff($this->end)->days + 1;
    }

    public function totalPence(int $pricePerDayPence): int
    {
        return $this->days() * $pricePerDayPence;
    }

    public function startString(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function endString(): string
    {
        return $this->end->format('Y-m-d');
    }

    /**
     * Why these dates cannot be booked for a listing, or null if they are fine.
     * $listing needs available_from / available_until (Y-m-d or null).
     */
    public function problemFor(array $listing, array $rules, ?DateTimeImmutable $now = null): ?string
    {
        $today = self::today($now);
        if ($this->start < $today) {
            return 'Rental start date cannot be in the past.';
        }
        if ($this->days() > $rules['max_rental_days']) {
            return 'Rentals can be at most ' . $rules['max_rental_days'] . ' days. Please choose a shorter period.';
        }
        if ($this->start > $today->modify('+' . $rules['max_advance_days'] . ' days')) {
            return 'Rentals can be requested at most ' . $rules['max_advance_days'] . ' days ahead.';
        }
        if (!empty($listing['available_from']) && $this->startString() < $listing['available_from']) {
            return 'This item is not available that early.';
        }
        if (!empty($listing['available_until']) && $this->endString() > $listing['available_until']) {
            return 'This item is not available that late.';
        }
        return null;
    }
}
