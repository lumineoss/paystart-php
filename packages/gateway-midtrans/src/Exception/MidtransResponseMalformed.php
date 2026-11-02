<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * Midtrans answered successfully but the body does not have the shape we require.
 *
 * Distinct from a rejection: this means our understanding of the API is wrong, so it
 * must surface loudly rather than being coerced into a plausible value.
 */
final class MidtransResponseMalformed extends RuntimeException implements PaystartException
{
    public static function missingField(string $gateway, string $field): self
    {
        return new self(\sprintf('Midtrans response is missing the "%s" field.', $field));
    }

    public static function unexpectedRefundKey(string $key): self
    {
        return new self(\sprintf(
            'Midtrans returned refund_key "%s", which is not the key that was sent. The '
            . 'refund cannot be matched to the request that produced it.',
            $key,
        ));
    }

    public static function unknownStatus(string $status): self
    {
        return new self(\sprintf(
            'Midtrans returned the unknown transaction_status "%s". Add it to the mapping '
            . 'rather than defaulting, or a real state will be silently mis-recorded.',
            $status,
        ));
    }
}
