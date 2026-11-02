<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use InvalidArgumentException;

final class InvalidArgument extends InvalidArgumentException implements PaystartException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
