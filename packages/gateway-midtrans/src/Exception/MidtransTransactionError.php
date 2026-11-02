<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * Midtrans answered with `status_code` 4xx or a `status_message` we treat as terminal.
 *
 * The message is derived from `status_code` only. `status_message` and any echoed
 * request fields are never copied into an exception: they can contain masked card
 * numbers, and exception messages end up in logs and error trackers.
 */
final class MidtransTransactionError extends RuntimeException implements PaystartException
{
    public static function forStatus(string $statusCode, string $httpStatus): self
    {
        return new self(\sprintf(
            'Midtrans rejected the request (status_code %s, http %s).',
            $statusCode,
            $httpStatus,
        ));
    }
}
