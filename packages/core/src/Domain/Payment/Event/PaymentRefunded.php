<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Event;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Event\AbstractDomainEvent;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;

/**
 * A refund was recorded on a payment.
 *
 * `remainingRefundable` is included because it is the number support desks and
 * reconciliation actually need, and deriving it downstream requires the whole refund
 * history.
 */
final readonly class PaymentRefunded extends AbstractDomainEvent
{
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        PaymentId $paymentId,
        ?string $merchantId,
        ?string $correlationId,
        public RefundId $refundId,
        public Money $refundAmount,
        public Money $remainingRefundable,
        public string $refundStatus,
    ) {
        parent::__construct($eventId, $occurredAt, $paymentId->value, $merchantId, $correlationId);
    }

    public function eventName(): string
    {
        return 'payment.refunded';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'refundId' => $this->refundId->value,
            'amountMinorUnits' => $this->refundAmount->minorUnits,
            'currency' => $this->refundAmount->currency->code,
            'remainingRefundableMinorUnits' => $this->remainingRefundable->minorUnits,
            'status' => $this->refundStatus,
        ];
    }
}
