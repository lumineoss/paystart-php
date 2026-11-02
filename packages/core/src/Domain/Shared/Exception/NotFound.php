<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use RuntimeException;

/**
 * An aggregate was looked up by an identifier that does not exist.
 *
 * Not the same as "not found for this merchant": that is an authorization
 * question and must never be answered with the same exception, or an attacker can
 * probe for identifiers.
 */
final class NotFound extends RuntimeException implements PaystartException
{
    public static function aggregate(string $aggregate, string $id): self
    {
        return new self(\sprintf('%s "%s" does not exist.', $aggregate, $id));
    }
}
