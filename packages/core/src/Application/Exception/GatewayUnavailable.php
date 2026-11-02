<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * A gateway call failed in a way that is not the caller's fault and may succeed on
 * retry (timeout, 5xx, transport error).
 *
 * Separate from a decline: a decline is a business outcome that moves the attempt to
 * failed, while this leaves the attempt in progress and lets the caller retry with
 * the same idempotency key.
 */
final class GatewayUnavailable extends RuntimeException implements PaystartException
{
    public static function for(string $gateway, string $reason): self
    {
        return new self(\sprintf('Gateway "%s" is unavailable: %s.', $gateway, $reason));
    }
}
