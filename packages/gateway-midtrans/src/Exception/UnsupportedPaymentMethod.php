<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Exception;

use LogicException;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethodKind;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * A payment method was requested that this adapter cannot express.
 *
 * Failing here rather than sending a charge Midtrans will reject keeps the failure at
 * the call site, where the caller can act on it.
 */
final class UnsupportedPaymentMethod extends LogicException implements PaystartException
{
    public static function kind(PaymentMethodKind $kind): self
    {
        return new self(\sprintf('Midtrans does not support the "%s" payment method kind.', $kind->value));
    }

    public static function channel(string $channel, string $kind): self
    {
        return new self(\sprintf('Midtrans has no "%s" channel for the "%s" method kind.', $channel, $kind));
    }

    public static function missingChannel(string $kind): self
    {
        return new self(\sprintf(
            'The "%s" method kind requires a channel (a bank, wallet, or store) for Midtrans.',
            $kind,
        ));
    }
}
