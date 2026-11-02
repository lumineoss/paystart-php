<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Exception;

use InvalidArgumentException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

final class MidtransConfigInvalid extends InvalidArgumentException implements PaystartException
{
    public static function missingServerKey(): self
    {
        return new self('Midtrans server key must not be empty.');
    }

    public static function malformedServerKey(): self
    {
        return new self('Midtrans server key must not contain control characters.');
    }

    /**
     * Midtrans documents that its notification sender may treat
     * `application/x-www-form-urlencoded` as JSON and send an empty body. A wrong
     * notification URL therefore fails silently, so it is validated at construction.
     */
    public static function malformedNotificationUrl(string $url): self
    {
        return new self(\sprintf(
            'Midtrans notification URL "%s" is not a valid http(s) URL. Midtrans will '
            . 'report an empty body if this is misconfigured, which fails silently.',
            $url,
        ));
    }
}
