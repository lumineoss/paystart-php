<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\AmountOverflow;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\CurrencyMismatch;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\UnknownCurrency;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private static function idr(): Currency
    {
        return Currency::of('IDR');
    }

    private static function jpy(): Currency
    {
        return Currency::of('JPY');
    }

    #[Test]
    public function it_reads_a_two_decimal_string_exactly(): void
    {
        self::assertSame(27500000, Money::fromString('275000.00', self::idr())->minorUnits);
    }

    #[Test]
    public function it_reads_a_string_without_decimals_as_whole_major_units(): void
    {
        self::assertSame(27500000, Money::fromString('275000', self::idr())->minorUnits);
    }

    #[Test]
    public function it_reads_a_zero_decimal_currency_without_scaling(): void
    {
        self::assertSame(500, Money::fromString('500', self::jpy())->minorUnits);
    }

    #[Test]
    public function it_accepts_trailing_zeros_beyond_the_exponent(): void
    {
        // "1.2300" is exactly 1.23, so it is representable; this must not throw.
        self::assertSame(123, Money::fromString('1.2300', self::idr())->minorUnits);
    }

    #[Test]
    public function it_rejects_a_fraction_that_cannot_be_represented(): void
    {
        $this->expectException(InvalidArgument::class);

        Money::fromString('1.234', self::idr());
    }

    #[Test]
    public function it_rejects_a_fraction_for_a_zero_decimal_currency(): void
    {
        $this->expectException(InvalidArgument::class);

        Money::fromString('100.5', self::jpy());
    }

    #[Test]
    public function it_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgument::class);

        Money::fromString('12,50', self::idr());
    }

    #[Test]
    public function it_parses_negative_amounts(): void
    {
        self::assertSame(-1250, Money::fromString('-12.50', self::idr())->minorUnits);
    }

    #[Test]
    public function of_major_scales_by_the_iso_exponent(): void
    {
        self::assertSame(27500000, Money::ofMajor(275000, self::idr())->minorUnits);
        self::assertSame(500, Money::ofMajor(500, self::jpy())->minorUnits);
    }

    /**
     * The trap ADR 0001 exists for: the ledger keeps ISO minor units while a
     * gateway wants whole rupiah. Both values are correct, they are just different
     * units, and the conversion must be explicit.
     */
    #[Test]
    public function the_same_payment_has_two_legitimate_representations(): void
    {
        $money = Money::ofMajor(275000, self::idr());

        self::assertSame(27500000, $money->minorUnits, 'ledger keeps ISO minor units');
        self::assertSame(275000, intdiv($money->minorUnits, $money->currency->minorUnitsPerMajor()), 'gateway wants whole rupiah');
    }

    #[Test]
    public function it_adds_amounts_of_the_same_currency(): void
    {
        $sum = Money::ofMinor(1000, self::idr())->plus(Money::ofMinor(250, self::idr()));

        self::assertSame(1250, $sum->minorUnits);
    }

    #[Test]
    public function it_refuses_to_add_different_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::ofMinor(1000, self::idr())->plus(Money::ofMinor(1000, self::jpy()));
    }

    #[Test]
    public function it_refuses_to_compare_different_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::ofMinor(1000, self::idr())->greaterThan(Money::ofMinor(1, self::jpy()));
    }

    #[Test]
    public function it_subtracts_to_a_negative_amount_rather_than_clamping(): void
    {
        $result = Money::ofMinor(100, self::idr())->minus(Money::ofMinor(250, self::idr()));

        self::assertSame(-150, $result->minorUnits);
        self::assertTrue($result->isNegative());
    }

    #[Test]
    public function it_detects_addition_overflow(): void
    {
        $this->expectException(AmountOverflow::class);

        Money::ofMinor(PHP_INT_MAX, self::idr())->plus(Money::ofMinor(1, self::idr()));
    }

    #[Test]
    public function it_detects_multiplication_overflow(): void
    {
        $this->expectException(AmountOverflow::class);

        Money::ofMinor(PHP_INT_MAX, self::idr())->multipliedBy(2);
    }

    #[Test]
    public function it_multiplies_exactly(): void
    {
        self::assertSame(3000, Money::ofMinor(1000, self::idr())->multipliedBy(3)->minorUnits);
    }

    #[Test]
    public function multiplying_by_zero_yields_zero_not_an_overflow(): void
    {
        self::assertSame(0, Money::ofMinor(PHP_INT_MAX, self::idr())->multipliedBy(0)->minorUnits);
    }

    #[Test]
    public function negating_the_minimum_integer_is_an_overflow_rather_than_a_silent_bug(): void
    {
        $this->expectException(AmountOverflow::class);

        Money::ofMinor(PHP_INT_MIN, self::idr())->negated();
    }

    #[Test]
    public function abs_returns_a_positive_amount(): void
    {
        self::assertSame(500, Money::ofMinor(-500, self::idr())->abs()->minorUnits);
    }

    #[Test]
    public function equality_compares_amount_and_currency(): void
    {
        self::assertTrue(Money::ofMinor(500, self::idr())->equals(Money::ofMinor(500, self::idr())));
        self::assertFalse(Money::ofMinor(500, self::idr())->equals(Money::ofMinor(500, self::jpy())));
        self::assertFalse(Money::ofMinor(500, self::idr())->equals(Money::ofMinor(501, self::idr())));
    }

    #[Test]
    public function zero_is_neither_positive_nor_negative(): void
    {
        $zero = Money::zero(self::idr());

        self::assertTrue($zero->isZero());
        self::assertFalse($zero->isPositive());
        self::assertFalse($zero->isNegative());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function acceptedAmounts(): iterable
    {
        yield 'integer' => ['275000', 27500000];
        yield 'two decimals' => ['275000.00', 27500000];
        yield 'one decimal' => ['1.5', 150];
        yield 'leading zeros' => ['0001.05', 105];
        yield 'zero' => ['0', 0];
        yield 'zero with decimals' => ['0.00', 0];
    }

    #[Test]
    #[DataProvider('acceptedAmounts')]
    public function it_parses_accepted_forms(string $input, int $expectedMinorUnits): void
    {
        self::assertSame($expectedMinorUnits, Money::fromString($input, self::idr())->minorUnits);
    }

    #[Test]
    public function an_unregistered_currency_is_an_error_not_a_two_decimal_guess(): void
    {
        $this->expectException(UnknownCurrency::class);

        Currency::of('XYZ');
    }
}
