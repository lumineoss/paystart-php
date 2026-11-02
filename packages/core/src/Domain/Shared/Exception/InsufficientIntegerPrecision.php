<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use InvalidArgumentException;

/**
 * On 32-bit builds PHP_INT_MAX is 2147483647, which cannot hold large IDR
 * amounts in minor units. That is a hard limitation worth failing loudly on
 * rather than silently truncating.
 */
final class InsufficientIntegerPrecision extends InvalidArgumentException implements PaystartException
{
    public static function forMonetaryAmounts(): self
    {
        return new self(\sprintf(
            'PHP_INT_MAX is %d on this build. Monetary amounts require a 64-bit PHP build.',
            PHP_INT_MAX,
        ));
    }
}
