<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use DomainException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * An operation would take a payment past what its own state allows, even though
 * the lifecycle transition itself is legal.
 *
 * Example: refunding more than was captured. The transition
 * `captured -> partially_refunded` is legal; the amount is not.
 */
final class RefundExceedsCapturedAmount extends DomainException implements PaystartException
{
    public static function attempted(int $requested, int $remaining): self
    {
        return new self(\sprintf(
            'Refund of %d minor units exceeds the %d that remains refundable. '
            . 'Cumulative refunds may never exceed the captured amount.',
            $requested,
            $remaining,
        ));
    }

    public static function nothingRemaining(): self
    {
        return new self('No refundable amount remains on this payment.');
    }
}
