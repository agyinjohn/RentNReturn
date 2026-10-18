<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * UK mobile numbers only (+447…). Restricting the destination is deliberate: SMS "pumping" fraud sends
 * verification texts to expensive international ranges, and RentNReturn launches in the UK.
 */
final class PhoneNumber
{
    /** "07700 900123", "+44 7700 900123", "0044..." → "+447700900123"; null if not a UK mobile. */
    public static function normalizeUkMobile(string $input): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($input));
        if (preg_match('/^(?:\+44|0044|44)(7\d{9})$/', $digits, $m) || preg_match('/^0(7\d{9})$/', $digits, $m)) {
            return '+44' . $m[1];
        }
        return null;
    }

    /** "+447700900123" → "07••• ••• 123" (for showing back to the owner of the number). */
    public static function mask(string $e164): string
    {
        return '07••• ••• ' . substr($e164, -3);
    }
}
