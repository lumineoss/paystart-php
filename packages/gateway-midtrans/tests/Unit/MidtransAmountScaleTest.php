<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Tests\Unit;

use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayAmount;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Gateway\Midtrans\Amount\MidtransAmountScale;
use Lumineoss\Paystart\Gateway\Midtrans\Exception\UnsupportedCurrency;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MidtransAmountScaleTest extends TestCase
{
    private MidtransAmountScale $scale;

    protected function setUp(): void
    {
        $this->scale = new MidtransAmountScale();
    }

    private function idr(int $minorUnits): Money
    {
        return Money::ofMinor($minorUnits, Currency::of('IDR'));
    }

    /**
     * ADR 0001 in one assertion: 275000 IDR is 27500000 minor units in the ledger and
     * 275000 whole rupiah at Midtrans.
     */
    #[Test]
    public function the_ledger_and_the_gateway_disagree_by_a_factor_of_one_hundred(): void
    {
        $money = $this->idr(27500000);

        self::assertSame(27500000, $money->minorUnits);
        self::assertSame(275000, $this->scale->toGateway($money)->toInt());
    }

    #[Test]
    public function it_converts_minor_units_to_whole_rupiah(): void
    {
        self::assertSame(1000, $this->scale->toGateway($this->idr(100000))->toInt());
    }

    #[Test]
    public function it_refuses_an_amount_that_is_not_a_whole_rupiah(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->scale->toGateway($this->idr(10050));
    }

    #[Test]
    public function it_refuses_a_non_idr_currency(): void
    {
        $this->expectException(UnsupportedCurrency::class);

        $this->scale->toGateway(Money::ofMinor(1000, Currency::of('USD')));
    }

    #[Test]
    public function it_reads_an_integer_gateway_amount(): void
    {
        $money = $this->scale->fromGateway(GatewayAmount::ofMinor(275000), Currency::of('IDR'));

        self::assertSame(27500000, $money->minorUnits);
    }

    /**
     * The notification sends "275000.00" while the charge request sent 275000. Both map
     * to the same ledger amount.
     */
    #[Test]
    public function it_reads_the_decimal_notification_form(): void
    {
        $money = $this->scale->fromDecimalString('275000.00', Currency::of('IDR'));

        self::assertSame(27500000, $money->minorUnits);
    }

    #[Test]
    public function it_reads_a_plain_notification_form(): void
    {
        self::assertSame(27500000, $this->scale->fromDecimalString('275000', Currency::of('IDR'))->minorUnits);
    }

    #[Test]
    public function it_reads_a_notification_with_zero_fractions(): void
    {
        self::assertSame(27500000, $this->scale->fromDecimalString('275000.0000', Currency::of('IDR'))->minorUnits);
    }

    /**
     * A sub-rupiah amount cannot be settled, so accepting it would create a ledger entry
     * no bank statement could ever match.
     */
    #[Test]
    public function it_refuses_a_non_zero_fraction_in_a_notification(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->scale->fromDecimalString('275000.50', Currency::of('IDR'));
    }

    #[Test]
    public function it_refuses_a_non_numeric_notification_amount(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->scale->fromDecimalString('275,000.00', Currency::of('IDR'));
    }

    #[Test]
    public function it_refuses_a_negative_notification_amount(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->scale->fromDecimalString('-275000.00', Currency::of('IDR'));
    }

    #[Test]
    public function it_refuses_an_implausibly_large_amount_rather_than_overflowing(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->scale->fromDecimalString('99999999999999999999999.00', Currency::of('IDR'));
    }

    #[Test]
    public function zero_survives_the_round_trip(): void
    {
        self::assertSame(0, $this->scale->toGateway($this->idr(0))->toInt());
        self::assertSame(0, $this->scale->fromDecimalString('0.00', Currency::of('IDR'))->minorUnits);
    }

    /**
     * The signature is computed over the string Midtrans sent, so this must be a plain
     * identity: normalising it would invalidate every genuine notification.
     */
    #[Test]
    public function the_signature_amount_is_never_normalised(): void
    {
        self::assertSame('275000.00', MidtransAmountScale::signatureAmount('275000.00'));
        self::assertSame('275000', MidtransAmountScale::signatureAmount('275000'));
    }

    #[Test]
    public function a_large_but_valid_amount_scales_exactly(): void
    {
        $money = $this->idr(9999999999999900);
        $gateway = $this->scale->toGateway($money);

        self::assertSame(99999999999999, $gateway->toInt());
        self::assertSame(9999999999999900, $this->scale->fromGateway($gateway, Currency::of('IDR'))->minorUnits);
    }
}
