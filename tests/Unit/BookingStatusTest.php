<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Booking\BookingStatus as S;
use PHPUnit\Framework\TestCase;

final class BookingStatusTest extends TestCase
{
    public function testOnlyThePaymentProviderCanConfirm(): void
    {
        foreach (['renter', 'owner'] as $role) {
            $this->assertFalse(S::canTransition(S::AWAITING_PAYMENT, S::CONFIRMED, $role));
            $this->assertArrayNotHasKey(S::CONFIRMED, S::actionsFor(S::AWAITING_PAYMENT, $role));
        }
    }

    public function testHappyPathRoles(): void
    {
        $this->assertTrue(S::canTransition(S::AWAITING_PAYMENT, S::CANCELLED, 'renter'));
        $this->assertTrue(S::canTransition(S::AWAITING_PAYMENT, S::CANCELLED, 'owner'));
        $this->assertTrue(S::canTransition(S::CONFIRMED, S::HANDOVER_READY, 'owner'));
        $this->assertFalse(S::canTransition(S::CONFIRMED, S::HANDOVER_READY, 'renter'));
        $this->assertTrue(S::canTransition(S::HANDOVER_READY, S::IN_RENTAL, 'renter'));
        $this->assertTrue(S::canTransition(S::IN_RENTAL, S::RETURN_PENDING, 'renter'));
        $this->assertFalse(S::canTransition(S::IN_RENTAL, S::RETURN_PENDING, 'owner'));
        $this->assertTrue(S::canTransition(S::RETURN_PENDING, S::COMPLETED, 'owner'));
        $this->assertFalse(S::canTransition(S::RETURN_PENDING, S::COMPLETED, 'renter'), 'Only the owner confirms the item came back');
    }

    public function testNoWayOutOfClosedStatesOrBackwards(): void
    {
        foreach ([S::COMPLETED, S::CANCELLED, S::EXPIRED, S::REFUNDED, S::DISPUTED] as $closed) {
            foreach (S::ALL as $to) {
                $this->assertFalse(S::canTransition($closed, $to, 'owner'), "{$closed} → {$to}");
                $this->assertFalse(S::canTransition($closed, $to, 'renter'), "{$closed} → {$to}");
            }
        }
        $this->assertFalse(S::canTransition(S::IN_RENTAL, S::CONFIRMED, 'owner'));
        // A paid booking cannot simply be cancelled: that needs the refund flow (Step 6).
        $this->assertFalse(S::canTransition(S::CONFIRMED, S::CANCELLED, 'renter'));
        $this->assertFalse(S::canTransition(S::CONFIRMED, S::CANCELLED, 'owner'));
    }

    public function testUnknownRoleCanDoNothing(): void
    {
        $this->assertSame([], S::actionsFor(S::AWAITING_PAYMENT, 'stranger'));
    }
}
