<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Payments\Fees;
use PHPUnit\Framework\TestCase;

final class FeesTest extends TestCase
{
    public function testOwnerPaysFee(): void
    {
        $this->assertSame(['rental' => 4797, 'fee' => 480, 'charge' => 4797, 'owner' => 4317], Fees::split(4797, 1000, 'owner'));
    }

    public function testRenterPaysFee(): void
    {
        $this->assertSame(['rental' => 4797, 'fee' => 480, 'charge' => 5277, 'owner' => 4797], Fees::split(4797, 1000, 'renter'));
    }

    public function testRoundingIsHalfUpToThePenny(): void
    {
        $this->assertSame(1, Fees::split(5, 1000, 'owner')['fee']);   // 0.5p → 1p
        $this->assertSame(0, Fees::split(4, 1000, 'owner')['fee']);   // 0.4p → 0p
        $this->assertSame(125, Fees::split(1245, 1000, 'owner')['fee']); // 124.5p → 125p
        $this->assertSame(0, Fees::split(1000, 0, 'owner')['fee']);
    }

    public function testSplitAlwaysAddsUp(): void
    {
        foreach ([1, 99, 1599, 4797, 123457, 600000] as $rental) {
            foreach ([0, 500, 1000, 1250] as $bps) {
                foreach (['owner', 'renter'] as $payer) {
                    $s = Fees::split($rental, $bps, $payer);
                    $this->assertSame($s['charge'], $s['owner'] + $s['fee'], "{$rental}/{$bps}/{$payer}");
                }
            }
        }
    }

    public function testOwnerShareAfterRefundIsProportional(): void
    {
        $this->assertSame(4317, Fees::ownerShareAfterRefund(4797, 4317, 0));
        $this->assertSame(0, Fees::ownerShareAfterRefund(4797, 4317, 4797));
        $this->assertSame(2159, Fees::ownerShareAfterRefund(4797, 4317, 2398)); // about half
        $this->assertSame(0, Fees::ownerShareAfterRefund(4797, 4317, 9999));
    }
}
