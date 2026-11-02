<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

/**
 * Provider-reported refund state.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
