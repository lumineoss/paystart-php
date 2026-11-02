<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * Monotonic version of an aggregate.
 *
 * Optimistic concurrency is not optional for payments: two concurrent captures
 * must not both succeed. The repository rejects a save whose version is not the
 * one the aggregate was loaded with.
 */
final readonly class Version
{
    public function __construct(
        public int $number,
    ) {
        if ($number < 0) {
            throw InvalidArgument::because(\sprintf('Version must not be negative, got %d.', $number));
        }
    }

    public static function initial(): self
    {
        return new self(0);
    }

    public function next(): self
    {
        return new self($this->number + 1);
    }

    public function equals(self $other): bool
    {
        return $this->number === $other->number;
    }

    public function isNewerThan(self $other): bool
    {
        return $this->number > $other->number;
    }
}
