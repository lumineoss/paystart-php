<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;

/**
 * Outcome of a refund request.
 *
 * A provider may accept a refund and settle it later, so a refund is not
 * guaranteed final when this object is returned: `status` says what the provider
 * claims right now.
 */
final readonly class RefundResult
{
    public function __construct(
        public RefundId $refundId,
        public GatewayReference $reference,
        public Money $amount,
        public RefundStatus $status,
    ) {
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }
}
