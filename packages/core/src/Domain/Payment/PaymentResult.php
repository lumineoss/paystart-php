<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * Outcome of a gateway call that creates or mutates a payment.
 *
 * A provider-shaped array never leaves the adapter: the application layer sees
 * only this. `instructions` is a list because one provider call can legitimately
 * return several payer-facing actions (an e-wallet returning both a QR and a
 * deeplink, for instance).
 */
final readonly class PaymentResult
{
    /**
     * @param list<PaymentInstruction> $instructions
     */
    public function __construct(
        public GatewayReference $reference,
        public PaymentStatus $status,
        public Money $amount,
        public array $instructions = [],
        public ?string $failureReason = null,
    ) {
    }

    /**
     * @return list<PaymentInstruction>
     */
    public function instructionsOfType(InstructionType $type): array
    {
        return array_values(array_filter(
            $this->instructions,
            static fn (PaymentInstruction $instruction): bool => $instruction->type === $type,
        ));
    }

    public function firstInstruction(): ?PaymentInstruction
    {
        return $this->instructions[0] ?? null;
    }

    public function isSuccessful(): bool
    {
        return \in_array($this->status, [
            PaymentStatus::Authorized,
            PaymentStatus::Captured,
            PaymentStatus::Settled,
        ], true);
    }
}
