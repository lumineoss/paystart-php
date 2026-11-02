<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use DomainException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * An attempt was started while another one is still live.
 *
 * One payment presents exactly one payer-facing action at a time. Starting a
 * second attempt without abandoning the first is how a payer ends up with two valid
 * QR codes and pays twice.
 */
final class AttemptAlreadyActive extends DomainException implements PaystartException
{
    public static function existing(string $attemptId): self
    {
        return new self(\sprintf(
            'Attempt "%s" is still active. Abandon it explicitly before starting another.',
            $attemptId,
        ));
    }
}
