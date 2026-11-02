<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use InvalidArgumentException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

final class InvalidPaymentMethod extends InvalidArgumentException implements PaystartException
{
    public static function channel(string $channel): self
    {
        return new self(\sprintf(
            'Payment method channel "%s" must be lowercase alphanumerics, hyphen, or underscore.',
            $channel,
        ));
    }
}
