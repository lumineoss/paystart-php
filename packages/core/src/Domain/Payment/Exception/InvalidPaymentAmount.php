<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use DomainException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * A payment would be created with a non-positive amount.
 */
final class InvalidPaymentAmount extends DomainException implements PaystartException
{
    public static function notPositive(int $minorUnits): self
    {
        return new self(\sprintf(
            'A payment amount must be strictly positive, got %d minor units.',
            $minorUnits,
        ));
    }
}
