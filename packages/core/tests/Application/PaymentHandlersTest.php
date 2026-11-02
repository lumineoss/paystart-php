<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Application\Command\CreatePayment;
use Lumineoss\Paystart\Core\Application\Command\RefundPayment;
use Lumineoss\Paystart\Core\Application\Exception\GatewayUnavailable;
use Lumineoss\Paystart\Core\Application\Exception\PaymentNotOwnedByMerchant;
use Lumineoss\Paystart\Core\Application\Handler\CreatePaymentHandler;
use Lumineoss\Paystart\Core\Application\Handler\RefundPaymentHandler;
use Lumineoss\Paystart\Core\Domain\Audit\AuditAction;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\RefundExceedsCapturedAmount;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\FrozenClock;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\IdGenerator;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Port\Persistence\TransactionManagerPort;
use Lumineoss\Paystart\Core\Tests\Application\Fake\FakeGateway;
use Lumineoss\Paystart\Core\Tests\Application\Fake\FakeTransactionManager;
use Lumineoss\Paystart\Core\Tests\Application\Fake\InMemoryAuditRepository;
use Lumineoss\Paystart\Core\Tests\Application\Fake\InMemoryOutbox;
use Lumineoss\Paystart\Core\Tests\Application\Fake\InMemoryPaymentRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentHandlersTest extends TestCase
{
    private FrozenClock $clock;

    private FakeGateway $gateway;

    private InMemoryPaymentRepository $payments;

    private InMemoryOutbox $outbox;

    private InMemoryAuditRepository $audit;

    private FakeTransactionManager $transactions;

    private IdGenerator $ids;

    private MerchantId $merchant;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-02T10:00:00+00:00'));
        $this->gateway = new FakeGateway();
        $this->payments = new InMemoryPaymentRepository();
        $this->outbox = new InMemoryOutbox();
        $this->audit = new InMemoryAuditRepository();
        $this->transactions = new FakeTransactionManager();
        $this->merchant = new MerchantId('mer_01j9x2k4m5n6p7q8r9s0t1u2v3');

        $sequence = 0;
        $this->ids = new class ($sequence) implements IdGenerator {
            public function __construct(private int $n)
            {
            }

            public function generate(string $prefix): string
            {
                $this->n++;

                return \sprintf('%s_%026d', $prefix, $this->n);
            }
        };
    }

    private function createHandler(): CreatePaymentHandler
    {
        return new CreatePaymentHandler(
            $this->gateway,
            $this->payments,
            $this->outbox,
            $this->audit,
            $this->transactions,
            new PaymentStateMachine(),
            $this->clock,
            $this->ids,
        );
    }

    private function refundHandler(): RefundPaymentHandler
    {
        return new RefundPaymentHandler(
            $this->gateway,
            $this->payments,
            $this->outbox,
            $this->audit,
            $this->transactions,
            $this->clock,
            $this->ids,
        );
    }

    private function idr(int $majorUnits): Money
    {
        return Money::ofMajor($majorUnits, Currency::of('IDR'));
    }

    private function createCommand(int $majorUnits = 275000): CreatePayment
    {
        return new CreatePayment(
            $this->merchant,
            $this->idr($majorUnits),
            PaymentMethod::qris(),
            new IdempotencyKey('create-1'),
        );
    }

    #[Test]
    public function creating_a_payment_persists_it_and_records_an_instruction(): void
    {
        $view = ($this->createHandler())($this->createCommand());

        self::assertSame(PaymentStatus::Pending, $view->status);
        self::assertSame(27500000, $view->amountMinorUnits);
        self::assertSame('IDR', $view->currency);
        self::assertSame('qr_string', $view->instructionType);
        self::assertNotNull($this->payments->find($view->id));
    }

    #[Test]
    public function creating_a_payment_emits_an_instruction_event_and_an_audit_entry(): void
    {
        ($this->createHandler())($this->createCommand());

        self::assertContains('payment.instruction_issued', $this->outbox->eventNames());
        self::assertContains(AuditAction::PaymentCreated->value, $this->audit->actions());
    }

    #[Test]
    public function a_pending_create_does_not_emit_a_status_change(): void
    {
        ($this->createHandler())($this->createCommand());

        self::assertNotContains(
            'payment.status_changed',
            $this->outbox->eventNames(),
            'a payment that is still pending has not changed status',
        );
    }

    #[Test]
    public function a_create_that_already_captured_emits_a_status_change(): void
    {
        $this->gateway->createStatus = PaymentStatus::Captured;

        $view = ($this->createHandler())($this->createCommand());

        self::assertSame(PaymentStatus::Captured, $view->status);
        self::assertContains('payment.status_changed', $this->outbox->eventNames());
    }

    #[Test]
    public function the_gateway_is_called_before_any_local_write(): void
    {
        $this->gateway->throwOnCreate = GatewayUnavailable::for('fake', 'timeout');

        try {
            ($this->createHandler())($this->createCommand());
            self::fail('expected the gateway failure to propagate');
        } catch (GatewayUnavailable) {
            // expected
        }

        self::assertSame(0, $this->transactions->begun, 'a failed gateway call must not open a transaction');
        self::assertSame([], $this->outbox->eventNames());
        self::assertSame(0, $this->audit->count());
    }

    #[Test]
    public function the_gateway_call_happens_outside_the_database_transaction(): void
    {
        $transactions = new class () implements TransactionManagerPort {
            public int $depth = 0;

            public int $maxDepth = 0;

            public function transactional(\Closure $operation): mixed
            {
                $this->depth++;
                $this->maxDepth = max($this->maxDepth, $this->depth);

                try {
                    return $operation();
                } finally {
                    $this->depth--;
                }
            }

            public function isInTransaction(): bool
            {
                return $this->depth > 0;
            }
        };

        $gateway = new class () extends FakeGateway {
            public ?\Lumineoss\Paystart\Core\Port\Persistence\TransactionManagerPort $transactions = null;

            public bool $calledInsideTransaction = false;

            public function createPayment(\Lumineoss\Paystart\Core\Port\Gateway\CreatePaymentRequest $request): \Lumineoss\Paystart\Core\Domain\Payment\PaymentResult
            {
                $this->calledInsideTransaction = $this->transactions?->isInTransaction() ?? false;

                return parent::createPayment($request);
            }
        };
        $gateway->transactions = $transactions;

        $handler = new CreatePaymentHandler(
            $gateway,
            $this->payments,
            $this->outbox,
            $this->audit,
            $transactions,
            new PaymentStateMachine(),
            $this->clock,
            $this->ids,
        );

        ($handler)($this->createCommand());

        self::assertFalse($gateway->calledInsideTransaction, 'holding a transaction open across a provider call risks exhausting the pool');
        self::assertSame(1, $transactions->maxDepth);
    }

    #[Test]
    public function a_refund_moves_the_payment_to_partially_refunded(): void
    {
        $created = ($this->createHandler())($this->createCommand());
        $this->markCaptured($created->id);

        $view = ($this->refundHandler())(
            new RefundPayment($created->id, $this->idr(100000), new IdempotencyKey('refund-1')),
            $this->merchant,
        );

        self::assertSame(PaymentStatus::PartiallyRefunded, $view->status);
        self::assertSame(10000000, $view->refundedMinorUnits);
        self::assertSame(17500000, $view->refundableMinorUnits);
    }

    #[Test]
    public function a_refund_of_the_full_amount_moves_the_payment_to_refunded(): void
    {
        $created = ($this->createHandler())($this->createCommand());
        $this->markCaptured($created->id);

        $view = ($this->refundHandler())(
            new RefundPayment($created->id, $this->idr(275000), new IdempotencyKey('refund-1')),
            $this->merchant,
        );

        self::assertSame(PaymentStatus::Refunded, $view->status);
        self::assertSame(0, $view->refundableMinorUnits);
    }

    #[Test]
    public function a_retried_refund_with_the_same_key_does_not_call_the_gateway_twice(): void
    {
        $created = ($this->createHandler())($this->createCommand());
        $this->markCaptured($created->id);

        $command = new RefundPayment($created->id, $this->idr(100000), new IdempotencyKey('refund-1'));

        ($this->refundHandler())($command, $this->merchant);
        ($this->refundHandler())($command, $this->merchant);

        self::assertSame(1, $this->gateway->refundCalls, 'the second call must be answered from stored state');
    }

    #[Test]
    public function an_over_refund_is_refused_before_the_gateway_is_contacted(): void
    {
        $created = ($this->createHandler())($this->createCommand(1000));
        $this->markCaptured($created->id);

        try {
            ($this->refundHandler())(
                new RefundPayment($created->id, $this->idr(5000), new IdempotencyKey('refund-1')),
                $this->merchant,
            );
            self::fail('expected the aggregate to refuse the over-refund');
        } catch (RefundExceedsCapturedAmount) {
            // expected
        }

        self::assertSame(0, $this->gateway->refundCalls, 'an invalid refund must never reach the provider');
    }

    #[Test]
    public function a_merchant_cannot_refund_another_merchants_payment(): void
    {
        $created = ($this->createHandler())($this->createCommand());
        $this->markCaptured($created->id);

        $this->expectException(PaymentNotOwnedByMerchant::class);

        ($this->refundHandler())(
            new RefundPayment($created->id, $this->idr(1000), new IdempotencyKey('refund-1')),
            new MerchantId('mer_01j9x2k4m5n6p7q8r9s0t1u2v9'),
        );
    }

    #[Test]
    public function a_refund_records_an_audit_entry_and_an_event(): void
    {
        $created = ($this->createHandler())($this->createCommand());
        $this->markCaptured($created->id);

        ($this->refundHandler())(
            new RefundPayment($created->id, $this->idr(1000), new IdempotencyKey('refund-1')),
            $this->merchant,
        );

        self::assertContains('payment.refunded', $this->outbox->eventNames());
        self::assertContains(AuditAction::RefundRequested->value, $this->audit->actions());
    }

    #[Test]
    public function transaction_bookkeeping_stays_balanced(): void
    {
        $created = ($this->createHandler())($this->createCommand());
        $this->markCaptured($created->id);

        ($this->refundHandler())(
            new RefundPayment($created->id, $this->idr(1000), new IdempotencyKey('refund-1')),
            $this->merchant,
        );

        $this->transactions->assertBalanced();

        self::assertSame(2, $this->transactions->committed, 'create and refund each committed once');
    }

    private function markCaptured(PaymentId $id): void
    {
        $payment = $this->payments->get($id);
        $payment->transitionTo(PaymentStatus::Captured, new PaymentStateMachine(), $this->clock->now());
        $this->payments->save($payment);
    }
}
