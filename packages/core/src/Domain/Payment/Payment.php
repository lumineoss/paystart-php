<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\AttemptAlreadyActive;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\InvalidPaymentAmount;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\NoActiveAttempt;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\PaymentAmountImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\RefundExceedsCapturedAmount;
use Lumineoss\Paystart\Core\Domain\Shared\AggregateMeta;
use Lumineoss\Paystart\Core\Domain\Shared\CustomerRef;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;

/**
 * The Payment aggregate root.
 *
 * Invariants enforced here, not in the application layer and certainly not in an
 * adapter:
 *   - amount and currency are set once and never change;
 *   - the status only moves forward, through PaymentStateMachine;
 *   - at most one attempt is live at any moment;
 *   - cumulative refunds never exceed the captured amount;
 *   - every attempt and refund is retained, because the audit trail is not optional.
 *
 * The aggregate is mutable through named methods only. There is no setter, and no
 * way to hand it a provider payload: adapters translate into these calls.
 */
final class Payment
{
    /** @var list<Attempt> */
    private array $attempts;

    /** @var list<Refund> */
    private array $refunds;

    private PaymentStatus $status;

    private AggregateMeta $meta;

    /**
     * @param list<Attempt> $attempts
     * @param list<Refund> $refunds
     */
    private function __construct(
        public readonly PaymentId $id,
        public readonly MerchantId $merchantId,
        public readonly Money $amount,
        public readonly PaymentMethod $method,
        public readonly ?CustomerRef $customer,
        public readonly ?string $description,
        AggregateMeta $meta,
        PaymentStatus $status,
        array $attempts,
        array $refunds,
    ) {
        $this->meta = $meta;
        $this->status = $status;
        $this->attempts = $attempts;
        $this->refunds = $refunds;
    }

    /**
     * Starting point for a new payment. Status is always Pending and there are no
     * attempts yet: an attempt is only created once the provider replies.
     */
    public static function create(
        PaymentId $id,
        MerchantId $merchantId,
        Money $amount,
        PaymentMethod $method,
        DateTimeImmutable $now,
        ?CustomerRef $customer = null,
        ?string $description = null,
    ): self {
        if (!$amount->isPositive()) {
            throw InvalidPaymentAmount::notPositive($amount->minorUnits);
        }

        return new self(
            $id,
            $merchantId,
            $amount,
            $method,
            $customer,
            $description,
            AggregateMeta::initial($now),
            PaymentStatus::Pending,
            [],
            [],
        );
    }

    /**
     * Rehydration from persistence. The stored status is authoritative and is not
     * re-derived from the attempt history.
     *
     * @param list<Attempt> $attempts
     * @param list<Refund> $refunds
     */
    public static function reconstitute(
        PaymentId $id,
        MerchantId $merchantId,
        Money $amount,
        PaymentMethod $method,
        ?CustomerRef $customer,
        ?string $description,
        AggregateMeta $meta,
        PaymentStatus $status,
        array $attempts,
        array $refunds,
    ): self {
        return new self(
            $id,
            $merchantId,
            $amount,
            $method,
            $customer,
            $description,
            $meta,
            $status,
            $attempts,
            $refunds,
        );
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function meta(): AggregateMeta
    {
        return $this->meta;
    }

    /**
     * @return list<Attempt>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    public function latestAttempt(): ?Attempt
    {
        return $this->attempts === [] ? null : $this->attempts[\count($this->attempts) - 1];
    }

    public function activeAttempt(): ?Attempt
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->isLive()) {
                return $attempt;
            }
        }

        return null;
    }

    /**
     * The instruction the payer should currently act on, if any.
     */
    public function currentInstruction(DateTimeImmutable $now): ?PaymentInstruction
    {
        return $this->activeAttempt()?->activeInstruction($now);
    }

    /**
     * @throws NoActiveAttempt
     */
    public function requireActiveInstruction(DateTimeImmutable $now): PaymentInstruction
    {
        return $this->currentInstruction($now) ?? throw NoActiveAttempt::for($this->id->value);
    }

    /**
     * Records a new attempt.
     *
     * Refuses while another attempt is live: two valid instructions at once means the
     * payer can pay twice. Callers that want a retry must expire the current attempt
     * first, which leaves a visible trace.
     *
     * @throws AttemptAlreadyActive
     */
    public function startAttempt(Attempt $attempt, DateTimeImmutable $now): void
    {
        $active = $this->activeAttempt();

        if ($active !== null) {
            throw AttemptAlreadyActive::existing($active->id->value);
        }

        $this->attempts[] = $attempt;
        $this->touch($now);
    }

    /**
     * Marks the live attempt as superseded and records the replacement.
     *
     * @throws NoActiveAttempt
     */
    public function replaceAttempt(Attempt $attempt, DateTimeImmutable $now): void
    {
        $active = $this->activeAttempt() ?? throw NoActiveAttempt::for($this->id->value);
        $active->expire();

        $this->attempts[] = $attempt;
        $this->touch($now);
    }

    /**
     * Moves the payment to a new status, guarding the transition.
     *
     * A same-state call is a no-op that does not bump the version, so a duplicate
     * webhook does not look like a change.
     */
    public function transitionTo(PaymentStatus $target, PaymentStateMachine $machine, DateTimeImmutable $now): void
    {
        if ($this->status === $target) {
            return;
        }

        $machine->assertCanTransition($this->status, $target);

        $this->status = $target;
        $this->touch($now);
    }

    /**
     * Money that can still be refunded: captured minus refunds that still consume
     * capacity.
     */
    public function refundableAmount(): Money
    {
        if (!$this->status->hasCollectedFunds()) {
            return Money::zero($this->amount->currency);
        }

        $consumed = Money::zero($this->amount->currency);

        foreach ($this->refunds as $refund) {
            if (!$refund->consumesCapacity()) {
                continue;
            }

            $consumed = $consumed->plus($refund->amount);
        }

        $remaining = $this->amount->minus($consumed);

        return $remaining->isNegative() ? Money::zero($this->amount->currency) : $remaining;
    }

    /**
     * @throws RefundExceedsCapturedAmount
     */
    public function registerRefund(Refund $refund, DateTimeImmutable $now): void
    {
        $this->assertCanRefund($refund->amount);

        $this->refunds[] = $refund;
        $this->touch($now);
    }

    /**
     * Validates a refund amount without recording anything.
     *
     * Exposed separately so an application handler can reject an invalid refund before
     * making a provider call. Checking only inside registerRefund would be too late:
     * by then the money has already been moved at the provider.
     *
     * @throws RefundExceedsCapturedAmount
     */
    public function assertCanRefund(Money $amount): void
    {
        if (!$this->status->isRefundable()) {
            throw RefundExceedsCapturedAmount::nothingRemaining();
        }

        $remaining = $this->refundableAmount();

        if ($amount->greaterThan($remaining)) {
            throw RefundExceedsCapturedAmount::attempted($amount->minorUnits, $remaining->minorUnits);
        }
    }

    /**
     * @return list<Refund>
     */
    public function refunds(): array
    {
        return $this->refunds;
    }

    public function findRefund(RefundId $id): ?Refund
    {
        foreach ($this->refunds as $refund) {
            if ($refund->id->equals($id)) {
                return $refund;
            }
        }

        return null;
    }

    public function hasRefundWithKey(string $idempotencyKey): bool
    {
        foreach ($this->refunds as $refund) {
            if (hash_equals($refund->idempotencyKey->value, $idempotencyKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Total refunded that still consumes capacity, for ledger postings.
     */
    public function totalRefunded(): Money
    {
        $total = Money::zero($this->amount->currency);

        foreach ($this->refunds as $refund) {
            if ($refund->consumesCapacity()) {
                $total = $total->plus($refund->amount);
            }
        }

        return $total;
    }

    /**
     * Guards the "amount never changes" invariant.
     *
     * Called by the application layer after reading a provider status, because a
     * mismatched amount is a reconciliation incident rather than a normal update.
     *
     * @throws PaymentAmountImmutable
     */
    public function assertAmountMatches(Money $reported): void
    {
        if ($reported->currency->code !== $this->amount->currency->code) {
            throw PaymentAmountImmutable::currencyChanged(
                $this->amount->currency->code,
                $reported->currency->code,
            );
        }

        if ($reported->minorUnits !== $this->amount->minorUnits) {
            throw PaymentAmountImmutable::changed($this->amount->minorUnits, $reported->minorUnits);
        }
    }

    public function belongsTo(MerchantId $merchantId): bool
    {
        return $this->merchantId->equals($merchantId);
    }

    private function touch(DateTimeImmutable $now): void
    {
        $this->meta = $this->meta->touched($now);
    }
}
