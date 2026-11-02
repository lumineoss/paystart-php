<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use DateTimeImmutable;

/**
 * Fixed clock for tests and replay scenarios.
 */
final readonly class FrozenClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): self
    {
        return new self($this->now->modify(\sprintf('+%d seconds', $seconds)));
    }
}
