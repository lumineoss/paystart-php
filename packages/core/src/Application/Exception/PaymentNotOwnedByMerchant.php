<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * A payment was requested for a merchant that does not own it.
 *
 * Intentionally distinct from `NotFound`: a caller that can distinguish "does not
 * exist" from "not yours" can enumerate other merchants' identifiers. Handlers must
 * translate this to whatever their API returns for a missing resource, not to a
 * different status that reveals the difference.
 */
final class PaymentNotOwnedByMerchant extends RuntimeException implements PaystartException
{
    public static function for(string $paymentId): self
    {
        return new self(\sprintf('Payment "%s" does not belong to the requested merchant.', $paymentId));
    }
}
