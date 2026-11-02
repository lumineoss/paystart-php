<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Amount;

use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayAmount;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Port\Gateway\GatewayAmountScale;
use Lumineoss\Paystart\Gateway\Midtrans\Exception\UnsupportedCurrency;

/**
 * Midtrans' amount scale for IDR.
 *
 * This is the concrete form of the problem ADR 0001 exists for. Three different
 * representations of the same payment coexist:
 *
 *   ledger              27500000   (ISO minor units; IDR has exponent 2)
 *   charge request      275000     (integer; Midtrans wants whole rupiah)
 *   notification        "275000.00" (string with two decimals)
 *
 * Getting this wrong is a factor-of-100 error in either direction, so every
 * conversion is a visible call and none of them goes through a float.
 *
 * The notification string is handled by a separate method on purpose: GatewayAmount
 * rejects "275000.00" by design, and loosening it would make a decimal amount silently
 * acceptable everywhere else in the library.
 */
final readonly class MidtransAmountScale implements GatewayAmountScale
{
    public function toGateway(Money $money): GatewayAmount
    {
        $this->assertSupported($money->currency);

        $perMajor = $money->currency->minorUnitsPerMajor();

        // Refuse rather than round: Midtrans cannot express a fractional rupiah, so an
        // amount that does not divide evenly is an upstream bug and rounding it would
        // change the amount of money charged.
        if ($money->minorUnits % $perMajor !== 0) {
            throw InvalidArgument::because(\sprintf(
                'Midtrans cannot express %d minor units of %s: IDR has no fractional unit.',
                $money->minorUnits,
                $money->currency->code,
            ));
        }

        return GatewayAmount::ofMinor(intdiv($money->minorUnits, $perMajor));
    }

    public function fromGateway(GatewayAmount $raw, Currency $currency): Money
    {
        $this->assertSupported($currency);

        return Money::ofMinor($raw->toInt() * $currency->minorUnitsPerMajor(), $currency);
    }

    /**
     * Parses the `gross_amount` string Midtrans sends in a notification, e.g.
     * "275000.00".
     *
     * Only an all-zero fractional part is accepted. "275000.50" would mean a sub-rupiah
     * amount, which Midtrans cannot actually settle, so accepting it would create a
     * ledger entry that no bank statement can ever match.
     */
    public function fromDecimalString(string $raw, Currency $currency): Money
    {
        $this->assertSupported($currency);

        if (preg_match('/\A(\d+)(?:\.(\d+))?\z/', $raw, $matches) !== 1) {
            throw InvalidArgument::because(\sprintf('Midtrans amount "%s" is not a decimal number.', $raw));
        }

        $fraction = $matches[2] ?? '';

        if (trim($fraction, '0') !== '') {
            throw InvalidArgument::because(\sprintf(
                'Midtrans amount "%s" has a non-zero fractional part; IDR has no fractional unit.',
                $raw,
            ));
        }

        if (\strlen($matches[1]) > 18) {
            throw InvalidArgument::because(\sprintf(
                'Midtrans amount "%s" is implausibly large and would overflow.',
                $raw,
            ));
        }

        return Money::ofMinor((int) $matches[1] * $currency->minorUnitsPerMajor(), $currency);
    }

    /**
     * The amount string exactly as received, for use in the signature.
     *
     * Midtrans computes `SHA512(order_id + status_code + gross_amount + ServerKey)`
     * using the string it sent. Normalising "275000.00" to "275000" first produces a
     * different digest and every notification would be rejected, so this method exists
     * purely to make that requirement impossible to forget.
     */
    public static function signatureAmount(string $rawGrossAmount): string
    {
        return $rawGrossAmount;
    }

    private function assertSupported(Currency $currency): void
    {
        if ($currency->code !== 'IDR') {
            throw UnsupportedCurrency::forMidtrans($currency->code);
        }
    }
}
