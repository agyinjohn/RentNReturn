<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ProfileController;
use App\Verification\PhoneNumber;
use App\Verification\TrustBadge;
use PHPUnit\Framework\TestCase;

final class VerificationRulesTest extends TestCase
{
    /** @dataProvider ukMobiles */
    public function testUkMobilesAreNormalised(string $input): void
    {
        $this->assertSame('+447700900123', PhoneNumber::normalizeUkMobile($input));
    }

    public static function ukMobiles(): array
    {
        return [['07700900123'], ['07700 900123'], ['077-0090-0123'], ['+447700900123'], ['+44 7700 900123'], ['447700900123'], ['00447700900123'], [' (07700) 900 123 ']];
    }

    /** @dataProvider notUkMobiles */
    public function testEverythingElseIsRefused(string $input): void
    {
        $this->assertNull(PhoneNumber::normalizeUkMobile($input));
    }

    public static function notUkMobiles(): array
    {
        return [
            ['01224 123456'],      // UK landline (Aberdeen)
            ['+1 415 555 0100'],   // US
            ['+882 1234 5678'],    // premium international range (SMS pumping target)
            ['0770090012'],        // too short
            ['077009001234'],      // too long
            ['+4407700900123'],
            ['seven seven'],
            [''],
        ];
    }

    public function testMaskShowsOnlyTheLastThreeDigits(): void
    {
        $this->assertSame('07••• ••• 123', PhoneNumber::mask('+447700900123'));
    }

    public function testBadgeNeedsAllThreeVerifications(): void
    {
        $all = ['email_verified_at' => '2026-10-01', 'phone_verified_at' => '2026-10-01', 'identity_verified_at' => '2026-10-01'];
        $this->assertTrue(TrustBadge::earned($all));
        foreach (array_keys($all) as $missing) {
            $this->assertFalse(TrustBadge::earned([$missing => null] + $all), "without {$missing}");
        }
        $this->assertFalse(TrustBadge::earned(['verified' => true, 'badge' => 1]), 'Unrelated flags never count');
        $this->assertSame('', TrustBadge::html(false));
        $this->assertStringContainsString('RentNReturn Verified ✓', TrustBadge::html(true));
    }

    public function testPublicNameIsDisplayNameOrFirstName(): void
    {
        $this->assertSame('Sam', TrustBadge::publicName(['name' => 'Samuel Okafor', 'display_name' => 'Sam']));
        $this->assertSame('Samuel', TrustBadge::publicName(['name' => '  Samuel Okafor ', 'display_name' => '']));
        $this->assertSame('Samuel', TrustBadge::publicName(['name' => 'Samuel Okafor', 'display_name' => null]));
    }

    public function testDisplayNameRules(): void
    {
        foreach (['Sam', "Mary-Jane O'Neil", 'Dr. Ade', 'Zoë', 'Tool Lender 2'] as $ok) {
            $this->assertNull(ProfileController::displayNameProblem($ok), $ok);
        }
        foreach (['S', str_repeat('a', 41), 'Call 07700900123', 'Sam 0770 090 0123', 'www.mytools.com', 'Sam <b>', 'sam@example.com', 'RentNReturn Support', 'Rent N Return Team'] as $bad) {
            $this->assertNotNull(ProfileController::displayNameProblem($bad), $bad);
        }
    }
}
