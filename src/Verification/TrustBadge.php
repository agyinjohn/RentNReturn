<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * The public "RentNReturn Verified ✓" rule and public naming — defined once, used everywhere.
 *
 * Badge = verified email AND verified mobile AND passed identity check. It is never stored as a flag:
 * it is derived from three timestamps that only server-side verification code can set
 * (email link, SMS code, identity-provider result), so no form or request can switch it on.
 */
final class TrustBadge
{
    /** SQL boolean expression for the badge, for a users table aliased $alias. */
    public static function sql(string $alias): string
    {
        return "({$alias}.email_verified_at IS NOT NULL AND {$alias}.phone_verified_at IS NOT NULL AND {$alias}.identity_verified_at IS NOT NULL)";
    }

    /** SQL expression for the public name: the chosen display name, else the first word of the legal name. */
    public static function publicNameSql(string $alias): string
    {
        return "COALESCE(NULLIF(TRIM({$alias}.display_name), ''), SUBSTRING_INDEX(TRIM({$alias}.name), ' ', 1))";
    }

    public static function earned(array $user): bool
    {
        return !empty($user['email_verified_at']) && !empty($user['phone_verified_at']) && !empty($user['identity_verified_at']);
    }

    public static function publicName(array $user): string
    {
        $display = trim((string) ($user['display_name'] ?? ''));
        return $display !== '' ? $display : explode(' ', trim((string) ($user['name'] ?? '')))[0];
    }

    /** Badge markup (empty when not earned). */
    public static function html(bool $earned): string
    {
        return $earned
            ? '<span class="verified-badge" title="Email, mobile and identity verified by RentNReturn">RentNReturn Verified ✓</span>'
            : '';
    }
}
