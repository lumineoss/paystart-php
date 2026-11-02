<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

final readonly class CaptureRequest
{
    public function __construct(
        public GatewayReference $reference,
        public Money $amount,
        public IdempotencyKey $idempotencyKey,
    ) {
    }
}
