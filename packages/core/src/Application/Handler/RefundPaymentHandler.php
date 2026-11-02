<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Handler;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Application\Command\RefundPayment;
use Lumineoss\Paystart\Core\Application\Dto\PaymentView;
use Lumineoss\Paystart\Core\Application\Exception\PaymentNotOwnedByMerchant;
use Lumineoss\Paystart\Core\Domain\Audit\AuditAction;
use Lumineoss\Paystart\Core\Domain\Audit\AuditEntry;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentRefunded;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentStatusChanged;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\NoActiveAttempt;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\Refund;
use Lumineoss\Paystart\Core\Domain\Payment\RefundStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Clock;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdGenerator;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;
use Lumineoss\Paystart\Core\Port\Gateway\PaymentGatewayPort;
use Lumineoss\Paystart\Core\Port\Gateway\RefundRequest;
use Lumineoss\Paystart\Core\Port\Persistence\AuditRepository;
use Lumineoss\Paystart\Core\Port\Persistence\OutboxRepository;
use Lumineoss\Paystart\Core\Port\Persistence\PaymentRepository;
use Lumineoss\Paystart\Core\Port\Persistence\TransactionManagerPort;

/**
 * Refunds a payment, fully or partially.
 *
 * Idempotency is checked before the gateway is called, using the key the caller
 * supplied. Without that check a retried refund would create a second refund at the
 * provider, and providers do not all deduplicate on their side.
 *
 * As with create, the gateway call sits outside the transaction; the same reasoning
 * applies and the idempotency key is what makes it safe.
 */
final readonly class RefundPaymentHandler
{
    public function __construct(
        private PaymentGatewayPort $gateway,
        private PaymentRepository $payments,
        private OutboxRepository $outbox,
        private AuditRepository $audit,
        private TransactionManagerPort $transactions,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    /**
     * @param MerchantId $merchantId the caller's
     *        merchant, used to refuse cross-merchant access
     *
     * @throws PaymentNotOwnedByMerchant
     */
    public function __invoke(RefundPayment $command, MerchantId $merchantId): PaymentView
    {
        $now = $this->clock->now();

        $payment = $this->payments->get($command->paymentId);

        if (!$payment->belongsTo($merchantId)) {
            // Same exception shape whichever merchant it belongs to, and no detail
            // about the real owner.
            throw PaymentNotOwnedByMerchant::for($command->paymentId->value);
        }

        // A caller that retried with the same key gets the stored state, not a second
        // refund. This must happen before any provider call.
        if ($payment->hasRefundWithKey($command->idempotencyKey->value)) {
            return self::view($payment, $now);
        }

        $reference = $this->requireGatewayReference($payment);

        // Reject an impossible refund before the provider is contacted. Validating
        // only at registerRefund time would be too late: the money would already have
        // moved at the gateway.
        $payment->assertCanRefund($command->amount);

        $draft = new Refund(
            new RefundId($this->ids->generate('rfd')),
            $command->amount,
            $command->idempotencyKey,
            $command->reason,
            $reference,
        );

        $result = $this->gateway->refund(new RefundRequest(
            $reference,
            $command->amount,
            $command->idempotencyKey,
            $command->reason,
        ));

        return $this->transactions->transactional(function () use ($payment, $draft, $result, $command, $now): PaymentView {
            $previousStatus = $payment->status();

            $payment->registerRefund($draft, $now);

            if ($result->status === RefundStatus::Succeeded) {
                $draft->markSucceeded();
            } elseif ($result->status === RefundStatus::Failed) {
                $draft->markFailed();
            }

            $target = $payment->refundableAmount()->isZero()
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;

            $payment->transitionTo($target, new PaymentStateMachine(), $now);

            $this->payments->save($payment);

            $correlation = $command->correlationId;

            $this->outbox->append(new PaymentRefunded(
                $this->ids->generate('evt'),
                $now,
                $payment->id,
                $payment->merchantId->value,
                $correlation,
                $draft->id,
                $draft->amount,
                $payment->refundableAmount(),
                $draft->status()->value,
            ));

            if ($payment->status() !== $previousStatus) {
                $this->outbox->append(PaymentStatusChanged::from(
                    $payment,
                    $previousStatus,
                    $this->ids->generate('evt'),
                    $now,
                    $correlation,
                ));
            }

            $this->audit->append(new AuditEntry(
                AuditAction::RefundRequested,
                $now,
                $payment->merchantId->value,
                $payment->id->value,
                'api',
                null,
                [
                    'refundId' => $draft->id->value,
                    'amountMinorUnits' => $draft->amount->minorUnits,
                    'currency' => $draft->amount->currency->code,
                    'gatewayStatus' => $result->status->value,
                    'gateway' => $this->gateway->name(),
                ],
                $correlation,
            ));

            return self::view($payment, $now);
        });
    }

    /**
     * The reference of the attempt that actually collected the funds.
     *
     * Refunding against the newest attempt would be wrong after a retry: the last
     * attempt may never have succeeded. Falling back to the newest reference keeps the
     * common case working when an adapter did not record one per attempt.
     */
    private function requireGatewayReference(Payment $payment): GatewayReference
    {
        foreach (array_reverse($payment->attempts()) as $attempt) {
            if ($attempt->gatewayReference !== null) {
                return $attempt->gatewayReference;
            }
        }

        throw NoActiveAttempt::for($payment->id->value);
    }

    private static function view(Payment $payment, DateTimeImmutable $now): PaymentView
    {
        $instruction = $payment->currentInstruction($now);

        return new PaymentView(
            $payment->id,
            $payment->status(),
            $payment->amount->minorUnits,
            $payment->amount->currency->code,
            $payment->method->describe(),
            $payment->totalRefunded()->minorUnits,
            $payment->refundableAmount()->minorUnits,
            $instruction?->type->value,
            $instruction?->expiresAt?->format(DATE_ATOM),
        );
    }
}
