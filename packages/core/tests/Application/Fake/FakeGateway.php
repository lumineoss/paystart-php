<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\Capability;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentResult;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\RefundResult;
use Lumineoss\Paystart\Core\Domain\Payment\RefundStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;
use Lumineoss\Paystart\Core\Port\Gateway\CancelRequest;
use Lumineoss\Paystart\Core\Port\Gateway\CaptureRequest;
use Lumineoss\Paystart\Core\Port\Gateway\CreatePaymentRequest;
use Lumineoss\Paystart\Core\Port\Gateway\CreateSubscriptionRequest;
use Lumineoss\Paystart\Core\Port\Gateway\GatewayStatusSnapshot;
use Lumineoss\Paystart\Core\Port\Gateway\PaymentGatewayPort;
use Lumineoss\Paystart\Core\Port\Gateway\RefundRequest;
use Lumineoss\Paystart\Core\Port\Gateway\SubscriptionResult;
use Throwable;

/**
 * Scriptable gateway double.
 *
 * Tests can queue an outcome or an exception per operation, which is how the
 * "gateway failed, nothing was persisted" paths get exercised.
 */
class FakeGateway implements PaymentGatewayPort
{
    public int $createCalls = 0;

    public int $refundCalls = 0;

    public PaymentStatus $createStatus = PaymentStatus::Pending;

    public ?Throwable $throwOnCreate = null;

    public ?Throwable $throwOnRefund = null;

    public function name(): string
    {
        return 'fake';
    }

    public function supports(Capability $capability): bool
    {
        return true;
    }

    public function declare(): array
    {
        return Capability::cases();
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        $this->createCalls++;

        if ($this->throwOnCreate !== null) {
            throw $this->throwOnCreate;
        }

        return new PaymentResult(
            new GatewayReference('fake-ref-' . $this->createCalls),
            $this->createStatus,
            $request->amount,
            [
                new PaymentInstruction(
                    InstructionType::QrString,
                    new QrPayload('00020101021226670016COM.GOOGLE.PAY'),
                    false,
                    $request->expiresAt,
                ),
            ],
        );
    }

    public function getStatus(GatewayReference $reference): GatewayStatusSnapshot
    {
        return new GatewayStatusSnapshot($reference, PaymentStatus::Pending, Money::ofMinor(0, Currency::of('IDR')), new DateTimeImmutable());
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        return new PaymentResult($request->reference, PaymentStatus::Captured, $request->amount);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $this->refundCalls++;

        if ($this->throwOnRefund !== null) {
            throw $this->throwOnRefund;
        }

        return new RefundResult(
            new RefundId('rfd_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            $request->reference,
            $request->amount,
            RefundStatus::Succeeded,
        );
    }

    public function cancel(CancelRequest $request): PaymentResult
    {
        return new PaymentResult($request->reference, PaymentStatus::Canceled, Money::ofMinor(0, Currency::of('IDR')));
    }

    public function createSubscription(CreateSubscriptionRequest $request): SubscriptionResult
    {
        return new SubscriptionResult(new GatewayReference('sub-1'), $request->interval, true);
    }

    public function disableSubscription(GatewayReference $subscriptionReference): void
    {
    }
}
