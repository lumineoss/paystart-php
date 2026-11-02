<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared\Exception;

use Throwable;

/**
 * Marker for every exception this library raises deliberately.
 *
 * Callers can catch this to distinguish library failures from programming
 * errors, while still being able to react to a specific subclass.
 */
interface PaystartException extends Throwable
{
}
