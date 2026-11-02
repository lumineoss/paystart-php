<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * ISO 4217 currency.
 *
 * Deliberately a value object rather than a backed enum: the ISO table changes
 * over time (new codes, changed exponents), and a value object lets an
 * application register a currency without a backwards-incompatible release.
 *
 * The exponent is the ISO minor-unit exponent, which is *not* necessarily the
 * scale a gateway accepts. See GatewayAmount.
 */
final readonly class Currency
{
    public bool $isZeroDecimal;

    public function __construct(
        public string $code,
        public int $exponent,
    ) {
        if (preg_match('/\A[A-Z]{3}\z/', $code) !== 1) {
            throw InvalidArgument::because(\sprintf(
                'Currency code must be three uppercase ISO 4217 letters, got "%s".',
                $code,
            ));
        }

        if ($exponent < 0 || $exponent > 4) {
            throw InvalidArgument::because(\sprintf(
                'Currency exponent must be between 0 and 4, got %d.',
                $exponent,
            ));
        }

        $this->isZeroDecimal = $exponent === 0;
    }

    public static function of(string $code): self
    {
        return CurrencyRegistry::default()->get($code);
    }

    /**
     * Number of minor units in one major unit: 10^exponent.
     */
    public function minorUnitsPerMajor(): int
    {
        $factor = 1;

        for ($i = 0; $i < $this->exponent; $i++) {
            $factor *= 10;
        }

        return $factor;
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
