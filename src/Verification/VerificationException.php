<?php

declare(strict_types=1);

namespace App\Verification;

/** A verification step was refused for a reason that is safe to show to the user. */
final class VerificationException extends \RuntimeException
{
}
