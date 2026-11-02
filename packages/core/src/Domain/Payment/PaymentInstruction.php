<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\AuthChallengePayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\LinkPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\RetailCodePayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\VirtualAccountPayload;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;

/**
 * An immutable, payer-facing next action.
 *
 * Instructions are created once and never mutated: refreshing an expired QR or
 * link produces a new instruction under a new attempt, which keeps the audit
 * trail honest about what the payer was actually shown.
 */
final readonly class PaymentInstruction
{
    public function __construct(
        public InstructionType $type,
        public InstructionPayload $payload,
        public bool $isStatic = false,
        public ?DateTimeImmutable $expiresAt = null,
        public ?GatewayReference $gatewayReference = null,
        public ?string $rawProviderType = null,
    ) {
        if (!$this->payloadMatchesType()) {
            throw InvalidArgument::because(\sprintf(
                'Instruction type "%s" does not accept a %s payload.',
                $this->type->value,
                $payload::class,
            ));
        }

        if ($this->isStatic && $this->expiresAt !== null) {
            throw InvalidArgument::because('A static instruction is reusable and must not declare an expiry.');
        }
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt <= $now;
    }

    public function isReusable(): bool
    {
        return $this->isStatic;
    }

    /**
     * Audit-safe description. Never contains URLs, QR content, or account numbers.
     */
    public function describeForAudit(): string
    {
        return \sprintf(
            '%s%s/%s',
            $this->type->value,
            $this->isStatic ? ':static' : ':dynamic',
            $this->payload->describeForAudit(),
        );
    }

    private function payloadMatchesType(): bool
    {
        return match ($this->type) {
            InstructionType::RedirectUrl,
            InstructionType::HostedPaymentLink,
            InstructionType::Deeplink => $this->payload instanceof LinkPayload,
            InstructionType::QrString,
            InstructionType::QrImageUrl => $this->payload instanceof QrPayload,
            InstructionType::VirtualAccount => $this->payload instanceof VirtualAccountPayload,
            InstructionType::RetailCode => $this->payload instanceof RetailCodePayload,
            InstructionType::AuthChallenge3ds => $this->payload instanceof AuthChallengePayload,
        };
    }
}
