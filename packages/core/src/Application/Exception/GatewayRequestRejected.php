<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * A gateway rejected the request for a reason that will not change on retry.
 *
 * The message is derived from the provider's error code, never copied raw: provider
 * error bodies can contain card data and internal identifiers, and this text ends up
 * in logs and in API responses.
 */
final class GatewayRequestRejected extends RuntimeException implements PaystartException
{
    public static function for(string $gateway, string $sanitisedReason): self
    {
        return new self(\sprintf('Gateway "%s" rejected the request: %s.', $gateway, $sanitisedReason));
    }
}
