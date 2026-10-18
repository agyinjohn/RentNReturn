<?php

declare(strict_types=1);

namespace App\Verification;

/** Used until a real identity provider is configured: identity checks are shown as "coming soon". */
final class UnavailableIdentityProvider implements IdentityProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function start(int $userId, string $returnUrl): array
    {
        throw new VerificationException('Identity checks are not available yet.');
    }
}
