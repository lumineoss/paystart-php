<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

/**
 * Billing cadence for a subscription.
 *
 * Validated values rather than a free string: a typo in an interval is a silent
 * billing bug.
 */
enum SubscriptionInterval: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    public function months(): int
    {
        return match ($this) {
            self::Daily => 0,
            self::Weekly => 0,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
        };
    }

    public function days(): int
    {
        return match ($this) {
            self::Daily => 1,
            self::Weekly => 7,
            default => 0,
        };
    }
}
