<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

/**
 * Lifecycle of a Payment.
 *
 * Transitions are guarded in PaymentStateMachine. Provider statuses are inputs to
 * a mapper, never this enum directly (see docs/domain-model.md).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Settled = 'settled';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Voided = 'voided';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return \in_array($this, [
            self::Refunded,
            self::Voided,
            self::Canceled,
            self::Expired,
            self::Failed,
        ], true);
    }

    /**
     * Whether the payment has collected money, even if that money is later
     * returned. `PartiallyRefunded` still counts: funds were captured.
     */
    public function hasCollectedFunds(): bool
    {
        return \in_array($this, [
            self::Captured,
            self::Settled,
            self::PartiallyRefunded,
            self::Refunded,
        ], true);
    }

    public function isRefundable(): bool
    {
        return \in_array($this, [
            self::Captured,
            self::Settled,
            self::PartiallyRefunded,
        ], true);
    }

    public function allowsRefundInPlace(): bool
    {
        return $this === self::PartiallyRefunded;
    }

    public function isAwaitingPayer(): bool
    {
        return $this === self::Pending;
    }
}
