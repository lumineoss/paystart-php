<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;

final readonly class CancelRequest
{
    public function __construct(
        public GatewayReference $reference,
        public IdempotencyKey $idempotencyKey,
        public ?string $reason = null,
    ) {
    }
}
