<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use Lumineoss\Paystart\Core\Domain\Payment\Exception\InvalidPaymentTransition;

/**
 * The only place that decides whether a payment may move from one status to
 * another.
 *
 * Kept as a table rather than a chain of conditionals so it can be read,
 * reviewed, and exhaustively tested. A payment may never move backwards: the
 * provider's notion of "reset to pending" is modelled as a new Attempt, not as a
 * reverse transition.
 */
final class PaymentStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'pending' => [
            'authorized',
            'captured',
            'settled',
            'failed',
            'expired',
            'canceled',
        ],
        'authorized' => [
            'captured',
            'voided',
            'expired',
            'canceled',
        ],
        'captured' => [
            'settled',
            'partially_refunded',
            'refunded',
        ],
        'settled' => [
            'partially_refunded',
            'refunded',
        ],
        'partially_refunded' => [
            'partially_refunded',
            'refunded',
        ],
    ];

    public function canTransition(PaymentStatus $from, PaymentStatus $to): bool
    {
        $allowed = self::ALLOWED[$from->value] ?? [];

        return \in_array($to->value, $allowed, true);
    }

    /**
     * @throws InvalidPaymentTransition
     */
    public function assertCanTransition(PaymentStatus $from, PaymentStatus $to): void
    {
        if ($from === $to && $from !== PaymentStatus::PartiallyRefunded) {
            throw InvalidPaymentTransition::sameState($from);
        }

        if ($this->canTransition($from, $to)) {
            return;
        }

        throw InvalidPaymentTransition::between($from, $to, $this->allowedTargets($from));
    }

    /**
     * @return list<PaymentStatus>
     */
    public function allowedTargets(PaymentStatus $from): array
    {
        return array_map(
            static fn (string $value): PaymentStatus => PaymentStatus::from($value),
            self::ALLOWED[$from->value] ?? [],
        );
    }
}
