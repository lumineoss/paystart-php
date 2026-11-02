<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Shared\CustomerRef;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

final readonly class CreateSubscriptionRequest
{
    public function __construct(
        public MerchantId $merchantId,
        public Money $amount,
        public PaymentMethod $method,
        public SubscriptionInterval $interval,
        public IdempotencyKey $idempotencyKey,
        public ?CustomerRef $customer = null,
    ) {
    }
}
