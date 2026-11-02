<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Command;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;

/**
 * Refresh the payer-facing instruction for a payment whose QR or link expired.
 *
 * Modelled as an explicit command rather than an automatic retry so the audit trail
 * shows that a human or a scheduled job asked for it.
 */
final readonly class RefreshInstruction
{
    public function __construct(
        public PaymentId $paymentId,
        public IdempotencyKey $idempotencyKey,
        public ?string $correlationId = null,
    ) {
    }
}
