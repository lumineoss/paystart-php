<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use DomainException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * A payment was asked for an instruction when it has no live attempt.
 */
final class NoActiveAttempt extends DomainException implements PaystartException
{
    public static function for(string $paymentId): self
    {
        return new self(\sprintf('Payment "%s" has no live attempt.', $paymentId));
    }
}
