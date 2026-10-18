<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * An identity-verification provider (e.g. Stripe Identity, Onfido). The provider holds the documents and
 * selfies; RentNReturn stores only the outcome and the provider's reference.
 *
 * Flow: start() returns a URL where the user completes the check on the provider's side. The provider then
 * reports the outcome server-to-server (signed webhook), which calls IdentityVerification::recordResult().
 */
interface IdentityProvider
{
    public function name(): string;

    /** Can checks be started right now? */
    public function available(): bool;

    /** @return array{reference: string, redirect_url: string} */
    public function start(int $userId, string $returnUrl): array;
}
