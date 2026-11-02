<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use Lumineoss\Paystart\Core\Domain\Payment\Exception\RefundExceedsCapturedAmount;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;

/**
 * A refund recorded against a payment.
 *
 * Kept inside the Payment aggregate rather than as its own root, because the only
 * invariant that matters is cumulative: the sum of refunds may never exceed the
 * captured amount. That is a decision that needs the whole set, so splitting it out
 * would require a cross-aggregate rule and eventual consistency on money.
 */
final class Refund
{
    private RefundStatus $status;

    public function __construct(
        public readonly RefundId $id,
        public readonly Money $amount,
        public readonly IdempotencyKey $idempotencyKey,
        public readonly ?string $reason,
        public readonly ?GatewayReference $gatewayReference,
        RefundStatus $status = RefundStatus::Pending,
    ) {
        if ($amount->isNegative() || $amount->isZero()) {
            throw RefundExceedsCapturedAmount::attempted($amount->minorUnits, 0);
        }

        $this->status = $status;
    }

    public function status(): RefundStatus
    {
        return $this->status;
    }

    public function markSucceeded(): void
    {
        // A refund that already failed is not silently revived; the provider must
        // issue a new refund with its own idempotency key.
        if ($this->status !== RefundStatus::Pending) {
            return;
        }

        $this->status = RefundStatus::Succeeded;
    }

    public function markFailed(): void
    {
        if ($this->status !== RefundStatus::Pending) {
            return;
        }

        $this->status = RefundStatus::Failed;
    }

    /**
     * Whether this refund still consumes refundable capacity.
     *
     * A failed refund releases its amount back, which is why this cannot simply sum
     * every refund ever created.
     */
    public function consumesCapacity(): bool
    {
        return $this->status !== RefundStatus::Failed;
    }
}
