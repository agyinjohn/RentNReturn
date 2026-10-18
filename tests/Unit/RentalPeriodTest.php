<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Booking\RentalPeriod;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/** Step 2 gate: one definition of rental days, prices in pence, past/out-of-window dates refused. */
final class RentalPeriodTest extends TestCase
{
    private const RULES = ['max_rental_days' => 60, 'max_advance_days' => 365];

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function testDaysAreInclusiveOfBothDates(): void
    {
        $this->assertSame(1, RentalPeriod::fromStrings('2030-03-04', '2030-03-04')->days());
        $this->assertSame(3, RentalPeriod::fromStrings('2030-03-04', '2030-03-06')->days());
        $this->assertSame(32, RentalPeriod::fromStrings('2030-01-31', '2030-03-03')->days(), 'Across month ends');
        // UK clocks go forward on 31 Mar 2030: the 23-hour day is still one rental day.
        $this->assertSame(3, RentalPeriod::fromStrings('2030-03-30', '2030-04-01')->days());
        $this->assertSame(3, RentalPeriod::fromStrings('2030-10-26', '2030-10-28')->days(), 'Clocks go back');
        $this->assertSame(2, RentalPeriod::fromStrings('2028-02-28', '2028-02-29')->days(), 'Leap day');
    }

    public function testTotalIsDaysTimesDailyPriceInPence(): void
    {
        $p = RentalPeriod::fromStrings('2030-03-04', '2030-03-06');
        $this->assertSame(3 * 1599, $p->totalPence(1599));
        $this->assertSame(4797, $p->totalPence(1599));
    }

    public function testMalformedOrReversedDatesAreRejected(): void
    {
        foreach ([['2030-03-06', '2030-03-04'], ['2030-02-30', '2030-03-01'], ['2030-3-4', '2030-03-05'], ['tomorrow', '2030-03-05'], ['', ''], ['2030-03-04', '2030-03-04 ']] as [$s, $e]) {
            $this->assertNull(RentalPeriod::fromStrings($s, $e), "{$s} → {$e}");
        }
    }

    public function testTodayIsTheUkDateNotTheServerDate(): void
    {
        // 23:30 UTC on 14 July is already 00:30 on 15 July in the UK (BST).
        $this->assertSame('2030-07-15', RentalPeriod::today($this->at('2030-07-14 23:30:00'))->format('Y-m-d'));
        // In winter the UK is on GMT, so the dates agree.
        $this->assertSame('2030-01-14', RentalPeriod::today($this->at('2030-01-14 23:30:00'))->format('Y-m-d'));
    }

    public function testPastDatesAreRefusedUsingUkToday(): void
    {
        $now = $this->at('2030-07-14 23:30:00'); // UK date: 15 July
        $this->assertStringContainsString('past', RentalPeriod::fromStrings('2030-07-14', '2030-07-16')->problemFor([], self::RULES, $now));
        $this->assertNull(RentalPeriod::fromStrings('2030-07-15', '2030-07-16')->problemFor([], self::RULES, $now), 'Today is allowed');
    }

    public function testAvailabilityWindowBoundaries(): void
    {
        $now = $this->at('2030-01-01 12:00:00');
        $listing = ['available_from' => '2030-02-10', 'available_until' => '2030-02-20'];
        $ok = static fn (string $s, string $e) => RentalPeriod::fromStrings($s, $e)->problemFor($listing, self::RULES, $now);

        $this->assertNull($ok('2030-02-10', '2030-02-20'), 'Exactly the window');
        $this->assertNull($ok('2030-02-10', '2030-02-10'), 'First day only');
        $this->assertNull($ok('2030-02-20', '2030-02-20'), 'Last day only');
        $this->assertStringContainsString('early', $ok('2030-02-09', '2030-02-12'));
        $this->assertStringContainsString('late', $ok('2030-02-18', '2030-02-21'));
        $this->assertNull(RentalPeriod::fromStrings('2030-02-01', '2030-02-02')->problemFor(['available_from' => null, 'available_until' => null], self::RULES, $now));
    }

    public function testLengthAndAdvanceLimits(): void
    {
        $now = $this->at('2030-01-01 12:00:00');
        $this->assertNull(RentalPeriod::fromStrings('2030-01-10', '2030-03-10')->problemFor([], self::RULES, $now), '60 days is allowed');
        $this->assertStringContainsString('at most 60 days', RentalPeriod::fromStrings('2030-01-10', '2030-03-11')->problemFor([], self::RULES, $now));
        $this->assertNull(RentalPeriod::fromStrings('2031-01-01', '2031-01-02')->problemFor([], self::RULES, $now), '365 days ahead');
        $this->assertStringContainsString('ahead', RentalPeriod::fromStrings('2031-01-02', '2031-01-03')->problemFor([], self::RULES, $now));
    }
}
