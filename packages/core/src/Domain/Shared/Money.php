<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\AmountOverflow;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\CurrencyMismatch;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InsufficientIntegerPrecision;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * An exact monetary amount.
 *
 * Stored as integer minor units, so there is no rounding and no float anywhere.
 * The currency carries the ISO exponent, which is what "minor units" means.
 *
 * Note what this class deliberately does NOT do:
 *   - it never converts to a gateway scale (that is GatewayAmountScale),
 *   - it never rounds (an explicit RoundingStrategy does that),
 *   - it never formats (the presentation layer does that).
 */
final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public Currency $currency,
    ) {
        if (PHP_INT_SIZE < 8) {
            throw InsufficientIntegerPrecision::forMonetaryAmounts();
        }
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Builds an amount from whole major units, e.g. `Money::ofMajor(275000, IDR)`.
     *
     * IDR has an ISO exponent of 2, so this yields 27500000 minor units. This is
     * intentional and is the trap described in ADR 0001: a gateway may want
     * `275000` while the ledger must still keep 27500000.
     */
    public static function ofMajor(int $majorUnits, Currency $currency): self
    {
        $factor = $currency->minorUnitsPerMajor();

        if ($majorUnits !== 0 && $factor > 1) {
            // Same guard as multipliedBy: PHP turns an overflowing integer
            // multiplication into a float, so the check must happen first.
            $limit = intdiv(PHP_INT_MAX, $factor);

            if ($majorUnits > $limit || $majorUnits < -$limit) {
                throw AmountOverflow::onMultiplication($majorUnits, $factor);
            }
        }

        return new self($majorUnits * $factor, $currency);
    }

    public static function ofMinor(int $minorUnits, Currency $currency): self
    {
        return new self($minorUnits, $currency);
    }

    /**
     * Parses a decimal string exactly, without ever converting to float.
     *
     * Accepts `"275000"`, `"275000.00"`, `"-12.5"`, `"0.001"`. Rejects values
     * with more decimals than the currency exponent allows, because silently
     * truncating a payment amount loses money.
     */
    public static function fromString(string $amount, Currency $currency): self
    {
        $pattern = '/\A(?<sign>[+-]?)(?<major>\d+)(?:\.(?<minor>\d+))?\z/';

        if (preg_match($pattern, trim($amount), $matches) !== 1) {
            throw InvalidArgument::because(\sprintf('Amount "%s" is not a decimal number.', $amount));
        }

        $minor = $matches['minor'] ?? '';
        $exponent = $currency->exponent;

        if (\strlen($minor) > $exponent) {
            $trimmed = rtrim($minor, '0');

            if (\strlen($trimmed) > $exponent) {
                throw InvalidArgument::because(\sprintf(
                    'Amount "%s" has more than %d decimal places allowed for %s.',
                    $amount,
                    $exponent,
                    $currency->code,
                ));
            }

            $minor = $trimmed;
        }

        $minor = str_pad($minor, $exponent, '0');

        $majorValue = (int) $matches['major'];
        $minorValue = $exponent === 0 ? 0 : (int) $minor;

        $sign = $matches['sign'] === '-' ? -1 : 1;

        return new self(($majorValue * $currency->minorUnitsPerMajor() + $minorValue) * $sign, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits) {
            throw AmountOverflow::onAddition($this->minorUnits, $other->minorUnits);
        }

        if ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits) {
            throw AmountOverflow::onAddition($this->minorUnits, $other->minorUnits);
        }

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->plus($other->negated());
    }

    public function negated(): self
    {
        if ($this->minorUnits === PHP_INT_MIN) {
            throw AmountOverflow::onNegation($this->minorUnits);
        }

        return new self(-$this->minorUnits, $this->currency);
    }

    public function abs(): self
    {
        return $this->minorUnits < 0 ? $this->negated() : $this;
    }

    /**
     * Multiplication by an integer factor, exact or failing.
     *
     * There is no float overload on purpose: a fractional multiplier would need
     * an explicit rounding strategy, which belongs to the caller.
     */
    public function multipliedBy(int $factor): self
    {
        if ($factor === 0 || $this->minorUnits === 0) {
            return self::zero($this->currency);
        }

        // Guard before multiplying: PHP silently promotes an overflowing integer
        // multiplication to float, so checking the product afterwards is too late
        // and intdiv() would receive a float.
        if ($this->wouldOverflowMultiplication($factor)) {
            throw AmountOverflow::onMultiplication($this->minorUnits, $factor);
        }

        return new self($this->minorUnits * $factor, $this->currency);
    }

    private function wouldOverflowMultiplication(int $factor): bool
    {
        if ($factor === -1) {
            return $this->minorUnits === PHP_INT_MIN;
        }

        if ($factor > 0) {
            return $this->minorUnits > intdiv(PHP_INT_MAX, $factor)
                || $this->minorUnits < intdiv(PHP_INT_MIN, $factor);
        }

        return $this->minorUnits < intdiv(PHP_INT_MAX, $factor)
            || $this->minorUnits > intdiv(PHP_INT_MIN, $factor);
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minorUnits === $other->minorUnits
            && $this->currency->equals($other->currency);
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits >= $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$this->currency->equals($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
