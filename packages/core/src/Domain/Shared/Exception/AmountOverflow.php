<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use RuntimeException;

final class AmountOverflow extends RuntimeException implements PaystartException
{
    public static function onAddition(int $left, int $right): self
    {
        return new self(\sprintf('Amount addition overflowed an integer: %d + %d.', $left, $right));
    }

    public static function onSubtraction(int $left, int $right): self
    {
        return new self(\sprintf('Amount subtraction overflowed an integer: %d - %d.', $left, $right));
    }

    public static function onMultiplication(int $amount, int $multiplier): self
    {
        return new self(\sprintf('Amount multiplication overflowed an integer: %d * %d.', $amount, $multiplier));
    }

    public static function onNegation(int $amount): self
    {
        return new self(\sprintf('Amount negation overflowed an integer: %d.', $amount));
    }
}
