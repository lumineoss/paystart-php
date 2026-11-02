<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use RuntimeException;

final class UnknownCurrency extends RuntimeException implements PaystartException
{
    public static function code(string $code): self
    {
        return new self(\sprintf(
            'Currency "%s" is not registered. Register it explicitly on CurrencyRegistry '
            . 'rather than relying on an implicit two-decimal default.',
            $code,
        ));
    }
}
