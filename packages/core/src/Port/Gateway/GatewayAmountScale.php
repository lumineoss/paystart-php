<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayAmount;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * Converts between our exact amounts and whatever unit a gateway accepts.
 *
 * This port exists because the two are genuinely different (ADR 0001). Midtrans
 * and Xendit want whole rupiah for IDR while the ledger keeps ISO minor units;
 * Stripe treats IDR as two-decimal and wants the minor units unchanged.
 *
 * Implementations must fail rather than round: silently rounding a payment
 * amount is how money disappears.
 */
interface GatewayAmountScale
{
    public function toGateway(Money $money): GatewayAmount;

    /**
     * The raw value is `int|string` because providers send amounts as JSON
     * numbers in some payloads and as decimal strings in others, and the exact
     * lexical form is needed for signature verification.
     */
    public function fromGateway(GatewayAmount $raw, Currency $currency): Money;
}
