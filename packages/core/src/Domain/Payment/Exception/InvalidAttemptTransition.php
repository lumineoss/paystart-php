<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Exception;

use LogicException;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;

/**
 * An attempt was moved between states it cannot occupy.
 *
 * Attempt transitions are stricter than payment transitions: an attempt is
 * create-once and final-once, so a late webhook cannot revive it.
 */
final class InvalidAttemptTransition extends LogicException implements PaystartException
{
    public static function between(AttemptStatus $from, AttemptStatus $to): self
    {
        return new self(\sprintf(
            'Cannot move an attempt from "%s" to "%s"; an attempt is final once it leaves a live state.',
            $from->value,
            $to->value,
        ));
    }
}
