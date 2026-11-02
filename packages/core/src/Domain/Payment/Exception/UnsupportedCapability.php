<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use LogicException;
use Lumineoss\Paystart\Core\Domain\Payment\Capability;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * A feature was used that the configured gateway or account does not support.
 *
 * Explicit by design. Silently degrading a payment (dropping a partial refund, or
 * charging the full amount when the payer asked for a partial one) is worse than
 * failing, and much harder to notice afterwards.
 */
final class UnsupportedCapability extends LogicException implements PaystartException
{
    public static function on(string $adapter, Capability $capability): self
    {
        return new self(\sprintf(
            'Gateway adapter "%s" does not support the "%s" capability.',
            $adapter,
            $capability->value,
        ));
    }
}
