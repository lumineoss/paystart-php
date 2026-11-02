<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use LogicException;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * A caller asked for a lifecycle change the model forbids.
 *
 * This is a programming error, not a provider error: an adapter must never let a
 * provider payload drive a payment backwards. Failing here is the whole point.
 */
final class InvalidPaymentTransition extends LogicException implements PaystartException
{
    /**
     * @param list<PaymentStatus> $allowed
     */
    public static function between(PaymentStatus $from, PaymentStatus $to, array $allowed): self
    {
        $targets = $allowed === []
            ? 'none: the state is terminal'
            : implode(', ', array_map(static fn (PaymentStatus $s): string => $s->value, $allowed));

        return new self(\sprintf(
            'Cannot move a payment from "%s" to "%s". Allowed from "%s": %s.',
            $from->value,
            $to->value,
            $from->value,
            $targets,
        ));
    }

    public static function sameState(PaymentStatus $state): self
    {
        return new self(\sprintf(
            'Payment is already in state "%s"; that transition is a no-op and is rejected to surface duplicate processing.',
            $state->value,
        ));
    }
}
