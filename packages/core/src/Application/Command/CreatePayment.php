<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Command;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Shared\CustomerRef;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * Ask the library to create a payment with the provider.
 *
 * The idempotency key is required, not optional. A create that is retried after a
 * timeout must not produce a second charge, and there is no way to reconstruct that
 * safety later.
 */
final readonly class CreatePayment
{
    /**
     * @param array<string, string> $metadata non-sensitive; never logged raw
     */
    public function __construct(
        public MerchantId $merchantId,
        public Money $amount,
        public PaymentMethod $method,
        public IdempotencyKey $idempotencyKey,
        public ?CustomerRef $customer = null,
        public ?string $description = null,
        public array $metadata = [],
        public ?string $correlationId = null,
    ) {
    }
}
