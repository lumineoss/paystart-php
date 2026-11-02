<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;

/**
 * UTC clock.
 *
 * Every timestamp the library records is UTC. Local time in a payment system is
 * how reconciliation reports end up disagreeing with the provider.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
