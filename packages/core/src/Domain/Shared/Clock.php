<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use DateTimeImmutable;

/**
 * Clock port.
 *
 * PSR-20 exists, but this interface is the one the domain uses so time-dependent
 * behaviour is testable and a replayed webhook can pin "now". A PSR-20 adapter
 * ships with the library, so hosts are not forced to adopt either.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
