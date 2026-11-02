<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

final readonly class RefundId extends PrefixedId
{
    public static function prefix(): string
    {
        return 'rfd';
    }
}
