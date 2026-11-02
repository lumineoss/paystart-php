<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Command;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * Refund all or part of a captured payment.
 *
 * The idempotency key is what makes a retried refund safe; the amount is explicit
 * even for a full refund so the caller's intent is recorded rather than inferred.
 */
final readonly class RefundPayment
{
    public function __construct(
        public PaymentId $paymentId,
        public Money $amount,
        public IdempotencyKey $idempotencyKey,
        public ?string $reason = null,
        public ?string $correlationId = null,
    ) {
    }
}
