<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

use Lumineoss\Paystart\Core\Domain\Shared\PrefixedId;

final readonly class PaymentId extends PrefixedId
{
    public static function prefix(): string
    {
        return 'pay';
    }
}
