<?php

declare(strict_types=1);

namespace App\Payments;

/**
 * The single definition of how a rental's money is split. Integer pence throughout.
 *
 *   payer = owner:  renter pays the rental price; the fee is deducted from the owner's share.
 *   payer = renter: renter pays the rental price + fee; the owner receives the full rental price.
 *
 * The fee is rounded half-up to the nearest penny. Stripe's own processing fee is paid by RentNReturn out of
 * its commission (separate charges and transfers: the charge is made on the platform account).
 */
final class Fees
{
    /** @return array{rental: int, fee: int, charge: int, owner: int} */
    public static function split(int $rentalPence, int $feeBps, string $payer): array
    {
        $fee = intdiv($rentalPence * $feeBps + 5000, 10000);
        return $payer === 'renter'
            ? ['rental' => $rentalPence, 'fee' => $fee, 'charge' => $rentalPence + $fee, 'owner' => $rentalPence]
            : ['rental' => $rentalPence, 'fee' => $fee, 'charge' => $rentalPence, 'owner' => $rentalPence - $fee];
    }

    /**
     * Owner's share after part of a payment has been refunded: the refund is shared in proportion, so the
     * owner and RentNReturn each give back their part of it.
     */
    public static function ownerShareAfterRefund(int $chargePence, int $ownerPence, int $refundedPence): int
    {
        if ($chargePence <= 0 || $refundedPence >= $chargePence) {
            return 0;
        }
        return intdiv($ownerPence * ($chargePence - $refundedPence) + intdiv($chargePence, 2), $chargePence);
    }
}
