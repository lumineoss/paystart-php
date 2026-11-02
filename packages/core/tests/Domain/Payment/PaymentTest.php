<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Domain\Payment;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Attempt;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptId;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\AttemptAlreadyActive;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\InvalidPaymentAmount;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\InvalidPaymentTransition;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\PaymentAmountImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\RefundExceedsCapturedAmount;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\Refund;
use Lumineoss\Paystart\Core\Domain\Payment\RefundStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentTest extends TestCase
{
    private DateTimeImmutable $now;

    private PaymentStateMachine $machine;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-02T10:00:00+00:00');
        $this->machine = new PaymentStateMachine();
    }

    private function idr(int $majorUnits): Money
    {
        return Money::ofMajor($majorUnits, Currency::of('IDR'));
    }

    private function newPayment(int $majorUnits = 275000): Payment
    {
        return Payment::create(
            PaymentId::fromString('pay_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            MerchantId::fromString('mer_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            $this->idr($majorUnits),
            PaymentMethod::qris(),
            $this->now,
            description: 'Order 5100',
        );
    }

    private function attempt(string $id = 'att_01j9x2k4m5n6p7q8r9s0t1u2v3'): Attempt
    {
        return Attempt::start(
            AttemptId::fromString($id),
            PaymentMethod::qris(),
            $this->now,
            null,
            [
                new PaymentInstruction(
                    InstructionType::QrString,
                    new QrPayload('00020101021226670016COM.GOOGLE.PAY'),
                    false,
                    $this->now->modify('+15 minutes'),
                ),
            ],
        );
    }

    #[Test]
    public function a_new_payment_is_pending_with_no_attempts(): void
    {
        $payment = $this->newPayment();

        self::assertSame(PaymentStatus::Pending, $payment->status());
        self::assertSame([], $payment->attempts());
        self::assertNull($payment->activeAttempt());
        self::assertSame(0, $payment->meta()->version->number);
    }

    #[Test]
    public function a_payment_cannot_be_created_with_a_zero_amount(): void
    {
        $this->expectException(InvalidPaymentAmount::class);

        $this->newPayment(0);
    }

    #[Test]
    public function the_amount_is_stored_in_iso_minor_units(): void
    {
        // 275000 IDR major units = 27500000 minor units. The gateway will want
        // 275000; the ledger must not silently use the gateway's scale.
        self::assertSame(27500000, $this->newPayment(275000)->amount->minorUnits);
    }

    #[Test]
    public function exactly_one_instruction_is_presented_at_a_time(): void
    {
        $payment = $this->newPayment();
        $payment->startAttempt($this->attempt(), $this->now);

        $instruction = $payment->requireActiveInstruction($this->now);

        self::assertSame(InstructionType::QrString, $instruction->type);
    }

    #[Test]
    public function starting_a_second_attempt_while_one_is_live_is_refused(): void
    {
        $payment = $this->newPayment();
        $payment->startAttempt($this->attempt(), $this->now);

        $this->expectException(AttemptAlreadyActive::class);

        $payment->startAttempt($this->attempt('att_01j9x2k4m5n6p7q8r9s0t1u2v4'), $this->now);
    }

    #[Test]
    public function replacing_an_attempt_expires_the_previous_one_and_keeps_it_for_audit(): void
    {
        $payment = $this->newPayment();
        $first = $this->attempt();
        $payment->startAttempt($first, $this->now);

        $payment->replaceAttempt($this->attempt('att_01j9x2k4m5n6p7q8r9s0t1u2v4'), $this->now);

        self::assertCount(2, $payment->attempts(), 'the old attempt is retained, not deleted');
        self::assertFalse($first->isLive(), 'the old attempt is no longer live');
        self::assertTrue($payment->activeAttempt()?->isLive() ?? false);
    }

    #[Test]
    public function an_expired_instruction_is_not_offered(): void
    {
        $payment = $this->newPayment();
        $payment->startAttempt($this->attempt(), $this->now);

        self::assertNull($payment->currentInstruction($this->now->modify('+16 minutes')));
    }

    #[Test]
    public function a_payment_moves_forward(): void
    {
        $payment = $this->newPayment();

        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);
        $payment->transitionTo(PaymentStatus::Settled, $this->machine, $this->now);

        self::assertSame(PaymentStatus::Settled, $payment->status());
    }

    #[Test]
    public function a_payment_never_moves_backwards(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);

        $this->expectException(InvalidPaymentTransition::class);

        $payment->transitionTo(PaymentStatus::Pending, $this->machine, $this->now);
    }

    #[Test]
    public function a_duplicate_same_state_call_is_a_no_op_and_does_not_bump_the_version(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);
        $version = $payment->meta()->version->number;

        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);

        self::assertSame($version, $payment->meta()->version->number, 'a replayed webhook must not look like a change');
    }

    #[Test]
    public function a_terminal_payment_cannot_be_reopened(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Failed, $this->machine, $this->now);

        $this->expectException(InvalidPaymentTransition::class);

        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);
    }

    #[Test]
    public function nothing_is_refundable_before_funds_are_collected(): void
    {
        self::assertTrue($this->newPayment()->refundableAmount()->isZero());
    }

    #[Test]
    public function the_full_captured_amount_is_refundable(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);

        self::assertSame(27500000, $payment->refundableAmount()->minorUnits);
    }

    private function refund(int $majorUnits, string $id = 'rfd_01j9x2k4m5n6p7q8r9s0t1u2v3', string $key = 'refund-1'): Refund
    {
        return new Refund(
            RefundId::fromString($id),
            $this->idr($majorUnits),
            new IdempotencyKey($key),
            'customer request',
            null,
        );
    }

    #[Test]
    public function a_partial_refund_reduces_the_refundable_remainder(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);

        $payment->registerRefund($this->refund(100000), $this->now);

        self::assertSame(17500000, $payment->refundableAmount()->minorUnits);
        self::assertSame(10000000, $payment->totalRefunded()->minorUnits);
    }

    #[Test]
    public function cumulative_refunds_cannot_exceed_the_captured_amount(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);
        $payment->registerRefund($this->refund(200000), $this->now);

        $this->expectException(RefundExceedsCapturedAmount::class);

        $payment->registerRefund($this->refund(100000, 'rfd_01j9x2k4m5n6p7q8r9s0t1u2v4', 'refund-2'), $this->now);
    }

    #[Test]
    public function a_failed_refund_releases_its_capacity(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);

        $refund = $this->refund(100000);
        $payment->registerRefund($refund, $this->now);
        self::assertSame(17500000, $payment->refundableAmount()->minorUnits);

        $refund->markFailed();

        self::assertSame(27500000, $payment->refundableAmount()->minorUnits, 'a failed refund must not consume capacity');
        self::assertTrue($payment->totalRefunded()->isZero());
    }

    #[Test]
    public function a_refund_cannot_be_registered_before_funds_are_collected(): void
    {
        $this->expectException(RefundExceedsCapturedAmount::class);

        $this->newPayment()->registerRefund($this->refund(1000), $this->now);
    }

    #[Test]
    public function the_full_refundable_amount_can_be_refunded_exactly(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);

        $payment->registerRefund($this->refund(275000), $this->now);

        self::assertTrue($payment->refundableAmount()->isZero());
    }

    #[Test]
    public function a_matching_provider_amount_passes_the_immutability_guard(): void
    {
        $payment = $this->newPayment(275000);

        $payment->assertAmountMatches($this->idr(275000));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_differing_provider_amount_is_rejected(): void
    {
        $payment = $this->newPayment(275000);

        $this->expectException(PaymentAmountImmutable::class);

        $payment->assertAmountMatches($this->idr(1000));
    }

    #[Test]
    public function a_differing_reported_currency_is_rejected(): void
    {
        $payment = $this->newPayment(275000);

        $this->expectException(PaymentAmountImmutable::class);

        $payment->assertAmountMatches(Money::ofMajor(275000, Currency::of('USD')));
    }

    #[Test]
    public function refunds_can_be_looked_up_by_idempotency_key(): void
    {
        $payment = $this->newPayment();
        $payment->transitionTo(PaymentStatus::Captured, $this->machine, $this->now);
        $payment->registerRefund($this->refund(1000, 'rfd_01j9x2k4m5n6p7q8r9s0t1u2v3', 'key-a'), $this->now);

        self::assertTrue($payment->hasRefundWithKey('key-a'));
        self::assertFalse($payment->hasRefundWithKey('key-b'));
    }

    #[Test]
    public function a_refund_that_already_succeeded_is_not_revived_by_a_late_failure(): void
    {
        $refund = $this->refund(1000);
        $refund->markSucceeded();
        $refund->markFailed();

        self::assertSame(RefundStatus::Succeeded, $refund->status());
    }
}
