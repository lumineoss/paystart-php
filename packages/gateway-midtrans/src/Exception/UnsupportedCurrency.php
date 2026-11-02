<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Exception;

use LogicException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * Midtrans supports IDR only.
 *
 * This is a capability limit, not a configuration error, so it fails loudly rather
 * than converting the amount into something Midtrans would accept but that means a
 * different amount of money.
 */
final class UnsupportedCurrency extends LogicException implements PaystartException
{
    public static function forMidtrans(string $currency): self
    {
        return new self(\sprintf(
            'Midtrans supports IDR only; refusing to charge in "%s". Convert currency '
            . 'and record the conversion before calling this gateway.',
            $currency,
        ));
    }
}
