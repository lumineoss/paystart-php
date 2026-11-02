<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\UnknownCurrency;

/**
 * Explicit currency table.
 *
 * There is no "default exponent" fallback on purpose. Assuming two decimals is
 * the single most common source of 10x and 100x payment errors, so an
 * unregistered code is an error rather than a guess.
 *
 * The table ships only the currencies named for v1. Extending it is a data
 * change, not a code change.
 */
final class CurrencyRegistry
{
    /** @var array<string, Currency> */
    private array $currencies = [];

    /** @var array<string, self> */
    private static array $instances = [];

    /**
     * @param list<Currency> $currencies
     */
    private function __construct(array $currencies)
    {
        foreach ($currencies as $currency) {
            $this->add($currency);
        }
    }

    public static function default(): self
    {
        return self::$instances[self::class] ??= new self([
            // ISO 4217 exponent 2. Stripe treats these as two-decimal too.
            new Currency('IDR', 2),
            new Currency('PHP', 2),
            new Currency('USD', 2),
            new Currency('SGD', 2),
            new Currency('MYR', 2),
            new Currency('EUR', 2),
            new Currency('GBP', 2),
            new Currency('THB', 2),
            // ISO 4217 zero-decimal currencies.
            new Currency('JPY', 0),
            new Currency('KRW', 0),
            new Currency('VND', 0),
        ]);
    }

    /**
     * An empty registry, for tests that must not inherit the default table.
     */
    public static function empty(): self
    {
        return new self([]);
    }

    public function add(Currency $currency): self
    {
        $this->currencies[$currency->code] = $currency;

        return $this;
    }

    public function has(string $code): bool
    {
        return isset($this->currencies[$code]);
    }

    /**
     * @throws UnknownCurrency
     */
    public function get(string $code): Currency
    {
        return $this->currencies[$code] ?? throw UnknownCurrency::code($code);
    }

    /**
     * Parses an ISO code from untrusted input (a gateway payload, a config file).
     */
    public function fromCode(string $code): Currency
    {
        $normalised = strtoupper(trim($code));

        if ($normalised === '') {
            throw InvalidArgument::because('Currency code must not be empty.');
        }

        return $this->get($normalised);
    }

    /**
     * @return list<Currency>
     */
    public function all(): array
    {
        return array_values($this->currencies);
    }
}
