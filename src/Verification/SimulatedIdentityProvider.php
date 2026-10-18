<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * DEVELOPMENT ONLY: lets a developer pass or fail an identity check on a local page, so the badge flow can be
 * built and demonstrated before a real provider account exists. ConfigValidator refuses to start staging or
 * production with this provider, and its page returns 404 outside development.
 */
final class SimulatedIdentityProvider implements IdentityProvider
{
    public function name(): string
    {
        return 'simulated';
    }

    public function available(): bool
    {
        return true;
    }

    public function start(int $userId, string $returnUrl): array
    {
        $reference = 'sim_' . bin2hex(random_bytes(8));
        return ['reference' => $reference, 'redirect_url' => '/verify/identity/simulate?ref=' . $reference];
    }
}
