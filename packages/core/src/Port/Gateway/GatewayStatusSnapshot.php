<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * A status read from the provider, used by the polling fallback.
 *
 * `rawStatus` is for audit and debugging only. Nothing in the domain may branch
 * on it; that is what keeps provider vocabulary out of business rules.
 */
final readonly class GatewayStatusSnapshot
{
    public function __construct(
        public GatewayReference $reference,
        public PaymentStatus $status,
        public Money $amount,
        public DateTimeImmutable $observedAt,
        public ?string $rawStatus = null,
    ) {
    }
}
