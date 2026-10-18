<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Booking\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @dataProvider validAmounts */
    public function testParsesPoundsIntoExactPence(string $input, int $pence): void
    {
        $this->assertSame($pence, Money::parsePounds($input));
    }

    public static function validAmounts(): array
    {
        return [
            ['12', 1200], ['12.5', 1250], ['12.50', 1250], ['0.01', 1], ['0.1', 10],
            ['£15.99', 1599], [' 7.05 ', 705], ['1,250.00', 125000],
            // Classic float traps: these must be exact.
            ['0.29', 29], ['1.15', 115], ['4.35', 435], ['19.99', 1999],
        ];
    }

    /** @dataProvider invalidAmounts */
    public function testRejectsAnythingThatIsNotAPlainAmount(string $input): void
    {
        $this->assertNull(Money::parsePounds($input));
    }

    public static function invalidAmounts(): array
    {
        return [[''], ['abc'], ['-5'], ['12.505'], ['1e3'], ['12.'], ['.50'], ['0x10'], ['12,50.1.1'], ['99999999']];
    }

    public function testFormatsPence(): void
    {
        $this->assertSame('£12.50', Money::format(1250));
        $this->assertSame('£0.05', Money::format(5));
        $this->assertSame('£1,234.00', Money::format(123400));
        $this->assertSame('12.05', Money::toPoundsString(1205));
    }
}
