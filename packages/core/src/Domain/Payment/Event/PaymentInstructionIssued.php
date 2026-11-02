<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Event;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Event\AbstractDomainEvent;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * A payer-facing instruction was issued.
 *
 * The payload describes the instruction; it never contains the instruction itself.
 * The URL, QR content, and account number are bearer-like: anyone who reads the
 * event stream could act on them. Subscribers that need the value must fetch it
 * through an authenticated read.
 */
final readonly class PaymentInstructionIssued extends AbstractDomainEvent
{
    public function __construct(
        string $eventId,
        DateTimeImmutable $occurredAt,
        PaymentId $paymentId,
        ?string $merchantId,
        ?string $correlationId,
        public string $instructionType,
        public bool $isStatic,
        public ?DateTimeImmutable $expiresAt,
        public Money $amount,
        public string $auditDescription,
    ) {
        parent::__construct($eventId, $occurredAt, $paymentId->value, $merchantId, $correlationId);
    }

    public static function from(
        PaymentInstruction $instruction,
        PaymentId $paymentId,
        Money $amount,
        ?string $merchantId,
        string $eventId,
        DateTimeImmutable $occurredAt,
        ?string $correlationId = null,
    ): self {
        return new self(
            $eventId,
            $occurredAt,
            $paymentId,
            $merchantId,
            $correlationId,
            $instruction->type->value,
            $instruction->isStatic,
            $instruction->expiresAt,
            $amount,
            $instruction->describeForAudit(),
        );
    }

    public function eventName(): string
    {
        return 'payment.instruction_issued';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'instructionType' => $this->instructionType,
            'isStatic' => $this->isStatic,
            'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
            'amountMinorUnits' => $this->amount->minorUnits,
            'currency' => $this->amount->currency->code,
            'description' => $this->auditDescription,
        ];
    }
}
