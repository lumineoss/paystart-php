<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Handler;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Application\Command\CreatePayment;
use Lumineoss\Paystart\Core\Application\Dto\PaymentView;
use Lumineoss\Paystart\Core\Domain\Audit\AuditAction;
use Lumineoss\Paystart\Core\Domain\Audit\AuditEntry;
use Lumineoss\Paystart\Core\Domain\Payment\Attempt;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptId;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentInstructionIssued;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentStatusChanged;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentResult;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Clock;
use Lumineoss\Paystart\Core\Domain\Shared\IdGenerator;
use Lumineoss\Paystart\Core\Port\Gateway\CreatePaymentRequest;
use Lumineoss\Paystart\Core\Port\Gateway\PaymentGatewayPort;
use Lumineoss\Paystart\Core\Port\Persistence\AuditRepository;
use Lumineoss\Paystart\Core\Port\Persistence\OutboxRepository;
use Lumineoss\Paystart\Core\Port\Persistence\PaymentRepository;
use Lumineoss\Paystart\Core\Port\Persistence\TransactionManagerPort;

/**
 * Creates a payment at the gateway and records it locally.
 *
 * Ordering matters and is deliberate:
 *   1. call the gateway first, because a local row for a payment the gateway never
 *      created is worse than a gateway payment with no local row (recoverable by
 *      reconciliation);
 *   2. write the aggregate, the outbox and the audit entry in one transaction;
 *   3. publish after commit, driven by the outbox table, not inline.
 *
 * The gateway call is deliberately outside the transaction. Holding a database
 * transaction open across a network call to a payment provider is how connection
 * pools die during an outage, and the idempotency key makes the retry safe.
 */
final readonly class CreatePaymentHandler
{
    public function __construct(
        private PaymentGatewayPort $gateway,
        private PaymentRepository $payments,
        private OutboxRepository $outbox,
        private AuditRepository $audit,
        private TransactionManagerPort $transactions,
        private PaymentStateMachine $machine,
        private Clock $clock,
        private IdGenerator $ids,
    ) {
    }

    public function __invoke(CreatePayment $command): PaymentView
    {
        $now = $this->clock->now();
        $paymentId = $this->payments->nextId();

        $payment = Payment::create(
            $paymentId,
            $command->merchantId,
            $command->amount,
            $command->method,
            $now,
            $command->customer,
            $command->description,
        );

        $result = $this->gateway->createPayment(new CreatePaymentRequest(
            $command->merchantId,
            $command->amount,
            $command->method,
            $command->idempotencyKey,
            $command->customer,
            $command->description,
            null,
            $command->metadata,
        ));

        $attempt = Attempt::start(
            new AttemptId($this->ids->generate('att')),
            $command->method,
            $now,
            $result->reference,
            $result->instructions,
        );

        return $this->transactions->transactional(function () use ($payment, $attempt, $result, $command, $now): PaymentView {
            $payment->startAttempt($attempt, $now);

            $this->applyGatewayStatus($payment, $result, $now);

            $this->payments->save($payment);

            $correlation = $command->correlationId;

            foreach ($result->instructions as $instruction) {
                $this->outbox->append(PaymentInstructionIssued::from(
                    $instruction,
                    $payment->id,
                    $payment->amount,
                    $payment->merchantId->value,
                    $this->ids->generate('evt'),
                    $now,
                    $correlation,
                ));
            }

            if ($payment->status() !== PaymentStatus::Pending) {
                $this->outbox->append(PaymentStatusChanged::from(
                    $payment,
                    PaymentStatus::Pending,
                    $this->ids->generate('evt'),
                    $now,
                    $correlation,
                ));
            }

            $this->audit->append(new AuditEntry(
                AuditAction::PaymentCreated,
                $now,
                $payment->merchantId->value,
                $payment->id->value,
                'system',
                null,
                [
                    'amountMinorUnits' => $payment->amount->minorUnits,
                    'currency' => $payment->amount->currency->code,
                    'method' => $payment->method->describe(),
                    'instructionCount' => \count($result->instructions),
                    'gateway' => $this->gateway->name(),
                ],
                $correlation,
            ));

            return self::view($payment, $now);
        });
    }

    /**
     * Applies the provider's initial status to a brand-new payment.
     *
     * A create call can already return an authorized or captured payment (a card sale
     * with immediate capture). Anything else leaves the payment pending, which is the
     * normal case for a QR or virtual account.
     */
    private function applyGatewayStatus(Payment $payment, PaymentResult $result, DateTimeImmutable $now): void
    {
        if ($result->status === PaymentStatus::Pending) {
            return;
        }

        $payment->assertAmountMatches($result->amount);
        $payment->transitionTo($result->status, $this->machine, $now);
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
