<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Tests\Support;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Attempt;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptId;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;

/**
 * Builds aggregates for persistence tests without going through a handler.
 */
final class PaymentFixture
{
    public static function merchant(): MerchantId
    {
        return new MerchantId('mer_01j9x2k4m5n6p7q8r9s0t1u2v3');
    }

    public static function time(string $iso = '2026-11-02T10:00:00+00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($iso);
    }

    public static function idr(int $majorUnits): Money
    {
        return Money::ofMajor($majorUnits, Currency::of('IDR'));
    }

    public static function pending(?string $id = null): Payment
    {
        return Payment::create(
            new \Lumineoss\Paystart\Core\Domain\Payment\PaymentId($id ?? 'pay_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            self::merchant(),
            self::idr(275000),
            PaymentMethod::qris(),
            self::time(),
        );
    }

    public static function attempt(
        ?string $id = null,
        ?GatewayReference $reference = null,
        ?PaymentInstruction $instruction = null,
    ): Attempt {
        return Attempt::start(
            new AttemptId($id ?? 'att_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            PaymentMethod::qris(),
            self::time(),
            $reference ?? new GatewayReference('midtrans-order-1'),
            [$instruction ?? self::instruction()],
        );
    }

    public static function instruction(): PaymentInstruction
    {
        return new PaymentInstruction(
            InstructionType::QrString,
            new QrPayload('00020101021226670016COM.GOOGLE.PAY'),
            false,
            self::time('2026-11-02T10:15:00+00:00'),
            new GatewayReference('midtrans-order-1'),
            'qris',
        );
    }

    /**
     * A payment with one attempt, transitioned to captured so refunds are allowed.
     */
    public static function captured(): Payment
    {
        $payment = self::pending();
        $payment->startAttempt(self::attempt(), self::time());
        $payment->transitionTo(PaymentStatus::Captured, new PaymentStateMachine(), self::time());

        return $payment;
    }
}
