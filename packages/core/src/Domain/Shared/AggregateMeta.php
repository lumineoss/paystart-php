<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use DateTimeImmutable;

/**
 * Audit metadata every aggregate root carries.
 *
 * `createdAt` and `updatedAt` are UTC. Storing local time in a payment system is
 * how reconciliation reports end up disagreeing with the provider.
 */
final readonly class AggregateMeta
{
    public function __construct(
        public Version $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public static function initial(DateTimeImmutable $now): self
    {
        return new self(Version::initial(), $now, $now);
    }

    public function touched(DateTimeImmutable $now): self
    {
        return new self($this->version->next(), $this->createdAt, $now);
    }
}
