<?php

declare(strict_types=1);

namespace App\Booking;

/**
 * All money is integer pence (GBP). Never floats: 0.1 + 0.2 !== 0.3 in floating point, which is how
 * rounding errors creep into totals, fees and refunds.
 */
final class Money
{
    /**
     * Parse a pounds amount typed by a person ("12", "12.5", "12.50", "£1,250.00") into pence.
     * Returns null for anything else (negative, more than 2 decimals, exponents, words...).
     */
    public static function parsePounds(string $input): ?int
    {
        $s = str_replace([',', '£', ' '], '', trim($input));
        if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $s, $m)) {
            return null;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** 1250 → "£12.50" */
    public static function format(int $pence): string
    {
        return '£' . number_format(intdiv($pence, 100)) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Pounds-and-pence string for form inputs: 1250 → "12.50" */
    public static function toPoundsString(int $pence): string
    {
        return intdiv($pence, 100) . '.' . str_pad((string) ($pence % 100), 2, '0', STR_PAD_LEFT);
    }
}
