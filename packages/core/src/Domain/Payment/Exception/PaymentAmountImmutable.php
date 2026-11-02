<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use DomainException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * A payment's amount or currency was supplied more than once and the values
 * disagree.
 *
 * Amount and currency are immutable after creation. A provider that reports a
 * different amount is telling us something is wrong, and silently adopting the new
 * value would corrupt the ledger.
 */
final class PaymentAmountImmutable extends DomainException implements PaystartException
{
    public static function changed(int $original, int $attempted): self
    {
        return new self(\sprintf(
            'A payment amount cannot change after it is set (was %d minor units, got %d).',
            $original,
            $attempted,
        ));
    }

    public static function currencyChanged(string $original, string $attempted): self
    {
        return new self(\sprintf(
            'A payment currency cannot change after it is set (was %s, got %s).',
            $original,
            $attempted,
        ));
    }
}
