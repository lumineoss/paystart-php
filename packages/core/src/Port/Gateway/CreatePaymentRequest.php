<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Shared\CustomerRef;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * Everything needed to start a payment.
 *
 * Deliberately an immutable object rather than a long parameter list, and
 * deliberately free of provider-specific keys: an adapter that needs a provider
 * option must map it from these fields or fail explicitly.
 */
final readonly class CreatePaymentRequest
{
    /**
     * @param array<string, string> $metadata non-sensitive key/value pairs; never logged raw
     */
    public function __construct(
        public MerchantId $merchantId,
        public Money $amount,
        public PaymentMethod $method,
        public IdempotencyKey $idempotencyKey,
        public ?CustomerRef $customer = null,
        public ?string $description = null,
        public ?DateTimeImmutable $expiresAt = null,
        public array $metadata = [],
    ) {
    }
}
