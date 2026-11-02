<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\InvalidAttemptTransition;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;

/**
 * One provider interaction to satisfy a Payment.
 *
 * An entity inside the Payment aggregate, not its own aggregate root. Attempts have
 * no independent lifecycle: one exists only to satisfy one payment, and the
 * "at most one live attempt" rule is a cross-attempt invariant that would be
 * unenforceable if attempts were separate roots.
 *
 * Mutable, because its status evolves; every mutation is guarded.
 */
final class Attempt
{
    private AttemptStatus $status;

    /** @var list<PaymentInstruction> */
    private array $instructions;

    private ?string $failureReason;

    /**
     * @param list<PaymentInstruction> $instructions
     */
    private function __construct(
        public readonly AttemptId $id,
        public readonly PaymentMethod $method,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?GatewayReference $gatewayReference,
        array $instructions,
        AttemptStatus $status,
        ?string $failureReason = null,
    ) {
        $this->status = $status;
        $this->failureReason = $failureReason;
        $this->instructions = $instructions;
    }

    /**
     * @param list<PaymentInstruction> $instructions
     */
    public static function start(
        AttemptId $id,
        PaymentMethod $method,
        DateTimeImmutable $startedAt,
        ?GatewayReference $gatewayReference,
        array $instructions,
        AttemptStatus $status = AttemptStatus::AwaitingAction,
    ): self {
        if ($status->isFinal()) {
            throw InvalidAttemptTransition::between(AttemptStatus::AwaitingAction, $status);
        }

        return new self($id, $method, $startedAt, $gatewayReference, $instructions, $status);
    }

    /**
     * Rehydration from persistence. Bypasses transition guards because the stored
     * state is authoritative, not a requested change.
     *
     * @param list<PaymentInstruction> $instructions
     */
    public static function reconstitute(
        AttemptId $id,
        PaymentMethod $method,
        DateTimeImmutable $startedAt,
        ?GatewayReference $gatewayReference,
        array $instructions,
        AttemptStatus $status,
        ?string $failureReason = null,
    ): self {
        return new self($id, $method, $startedAt, $gatewayReference, $instructions, $status, $failureReason);
    }

    public function status(): AttemptStatus
    {
        return $this->status;
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * @return list<PaymentInstruction>
     */
    public function instructions(): array
    {
        return $this->instructions;
    }

    public function latestInstruction(): ?PaymentInstruction
    {
        return $this->instructions === [] ? null : $this->instructions[\count($this->instructions) - 1];
    }

    /**
     * Latest instruction that is still usable at the given instant.
     */
    public function activeInstruction(DateTimeImmutable $now): ?PaymentInstruction
    {
        foreach (array_reverse($this->instructions) as $instruction) {
            if (!$instruction->isExpiredAt($now)) {
                return $instruction;
            }
        }

        return null;
    }

    public function markProcessing(): void
    {
        $this->transitionTo(AttemptStatus::Processing);
    }

    public function markAuthorized(): void
    {
        $this->transitionTo(AttemptStatus::Authorized);
    }

    public function markSucceeded(): void
    {
        $this->transitionTo(AttemptStatus::Succeeded);
    }

    public function markFailed(string $reason): void
    {
        $this->transitionTo(AttemptStatus::Failed);
        $this->failureReason = $reason;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function expire(): void
    {
        $this->transitionTo(AttemptStatus::Expired);
    }

    /**
     * Supersedes this attempt with a fresh instruction set.
     *
     * The old attempt is expired rather than deleted: the ledger and the audit log
     * must still be able to explain what the payer was shown.
     *
     * @param list<PaymentInstruction> $instructions
     */
    public function replaceInstructions(array $instructions): void
    {
        if (!$this->isLive()) {
            throw InvalidAttemptTransition::between($this->status, AttemptStatus::AwaitingAction);
        }

        $this->instructions = $instructions;
        $this->status = AttemptStatus::AwaitingAction;
    }

    private function transitionTo(AttemptStatus $target): void
    {
        if ($this->status->isFinal()) {
            throw InvalidAttemptTransition::between($this->status, $target);
        }

        // Processing is a real intermediate state; everything else follows the
        // order in which a provider reports it.
        $allowed = match ($this->status) {
            AttemptStatus::AwaitingAction => [
                AttemptStatus::Processing,
                AttemptStatus::Authorized,
                AttemptStatus::Succeeded,
                AttemptStatus::Failed,
                AttemptStatus::Expired,
                AttemptStatus::Canceled,
            ],
            AttemptStatus::Processing => [
                AttemptStatus::Authorized,
                AttemptStatus::Succeeded,
                AttemptStatus::Failed,
                AttemptStatus::Expired,
            ],
            AttemptStatus::Authorized => [
                AttemptStatus::Succeeded,
                AttemptStatus::Failed,
                AttemptStatus::Expired,
            ],
            default => [],
        };

        if (!\in_array($target, $allowed, true)) {
            throw InvalidAttemptTransition::between($this->status, $target);
        }

        $this->status = $target;
    }
}
