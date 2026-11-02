<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Dto;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;

/**
 * Read-only view of a payment for callers and for HTTP serialisation.
 *
 * Deliberately not the aggregate: handing the aggregate out would let a caller keep
 * a mutable reference, and would leak every internal method into the public API.
 * The instruction is included only as a description, never as a value.
 */
final readonly class PaymentView
{
    public function __construct(
        public PaymentId $id,
        public PaymentStatus $status,
        public int $amountMinorUnits,
        public string $currency,
        public string $method,
        public int $refundedMinorUnits,
        public int $refundableMinorUnits,
        public ?string $instructionType,
        public ?string $instructionExpiresAt,
    ) {
    }
}
