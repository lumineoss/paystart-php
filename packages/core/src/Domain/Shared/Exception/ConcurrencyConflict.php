<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Version;
use RuntimeException;

/**
 * Raised when a concurrent modification is detected, which for this library
 * usually means a double capture, a duplicate refund, or two workers handling the
 * same webhook.
 */
final class ConcurrencyConflict extends RuntimeException implements PaystartException
{
    public static function onSave(string $aggregate, Version $expected, Version $actual): self
    {
        return new self(\sprintf(
            'Concurrent modification of %s: expected version %d, stored version is %d.',
            $aggregate,
            $expected->number,
            $actual->number,
        ));
    }
}
