<?php

declare(strict_types=1);

namespace App\Payments;

/** A payment action was refused or failed for a reason that is safe to show to the user. */
final class PaymentException extends \RuntimeException
{
}
