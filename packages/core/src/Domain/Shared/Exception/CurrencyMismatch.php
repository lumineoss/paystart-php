<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use RuntimeException;

final class CurrencyMismatch extends RuntimeException implements PaystartException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(\sprintf(
            'Cannot operate on different currencies: %s and %s.',
            $left->code,
            $right->code,
        ));
    }
}
