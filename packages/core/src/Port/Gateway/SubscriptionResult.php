<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;

/**
 * Result of creating a recurring billing agreement at the provider.
 */
final readonly class SubscriptionResult
{
    public function __construct(
        public GatewayReference $reference,
        public SubscriptionInterval $interval,
        public bool $active,
    ) {
    }
}
