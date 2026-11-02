<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Event;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Event\AbstractDomainEvent;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * A payment's status changed.
 *
 * One event type for every transition rather than one class per status: subscribers
 * that care about a single transition filter on `to`, and adding a status does not
 * mean publishing a new class. The `previous` field is what lets a consumer
 * reconstruct ordering when events are delivered out of order.
 */
final readonly class PaymentStatusChanged extends AbstractDomainEvent
{
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        PaymentId $paymentId,
        ?string $merchantId,
        ?string $correlationId,
        public PaymentStatus $previous,
        public PaymentStatus $current,
        public Money $amount,
        public PaymentMethod $method,
    ) {
        parent::__construct($eventId, $occurredAt, $paymentId->value, $merchantId, $correlationId);
    }

    public static function from(
        Payment $payment,
        PaymentStatus $previous,
        string $eventId,
        DateTimeImmutable $occurredAt,
        ?string $correlationId = null,
    ): self {
        return new self(
            $eventId,
            $occurredAt,
            $payment->id,
            $payment->merchantId->value,
            $correlationId,
            $previous,
            $payment->status(),
            $payment->amount,
            $payment->method,
        );
    }

    public function eventName(): string
    {
        return 'payment.status_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'from' => $this->previous->value,
            'to' => $this->current->value,
            'amountMinorUnits' => $this->amount->minorUnits,
            'currency' => $this->amount->currency->code,
            'method' => $this->method->describe(),
        ];
    }
}
