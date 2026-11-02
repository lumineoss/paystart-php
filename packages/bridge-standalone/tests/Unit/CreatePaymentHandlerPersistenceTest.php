<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Tests\Unit;

use Lumineoss\Paystart\Bridge\Standalone\Persistence\PaymentHydrator;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\PdoAuditRepository;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\PdoOutboxRepository;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\PdoPaymentRepository;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\PdoTransactionManager;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\UlidIdGenerator;
use Lumineoss\Paystart\Bridge\Standalone\Tests\Support\PaymentFixture;
use Lumineoss\Paystart\Bridge\Standalone\Tests\Support\SqliteDatabase;
use Lumineoss\Paystart\Core\Application\Command\CreatePayment;
use Lumineoss\Paystart\Core\Application\Handler\CreatePaymentHandler;
use Lumineoss\Paystart\Core\Domain\Payment\Capability;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentResult;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\RefundResult;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\FrozenClock;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Port\Gateway\CancelRequest;
use Lumineoss\Paystart\Core\Port\Gateway\CaptureRequest;
use Lumineoss\Paystart\Core\Port\Gateway\CreatePaymentRequest;
use Lumineoss\Paystart\Core\Port\Gateway\CreateSubscriptionRequest;
use Lumineoss\Paystart\Core\Port\Gateway\GatewayStatusSnapshot;
use Lumineoss\Paystart\Core\Port\Gateway\PaymentGatewayPort;
use Lumineoss\Paystart\Core\Port\Gateway\RefundRequest;
use Lumineoss\Paystart\Core\Port\Gateway\SubscriptionResult;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end through the real handler, the real PDO repositories, and a real
 * transaction against a real database.
 *
 * This is the test the rest of the persistence work was for: it proves the pieces
 * compose, not just that each one works alone.
 */
final class CreatePaymentHandlerPersistenceTest extends TestCase
{
    private PDO $pdo;

    private PdoPaymentRepository $payments;

    private PdoOutboxRepository $outbox;

    private PdoAuditRepository $audit;

    private StubGateway $gateway;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::create();
        $ids = new UlidIdGenerator();

        $this->payments = new PdoPaymentRepository($this->pdo, new PaymentHydrator(), $ids);
        $this->outbox = new PdoOutboxRepository($this->pdo);
        $this->audit = new PdoAuditRepository($this->pdo);
        $this->gateway = new StubGateway();
        $this->clock = new FrozenClock(PaymentFixture::time());
    }

    private function handler(): CreatePaymentHandler
    {
        return new CreatePaymentHandler(
            $this->gateway,
            $this->payments,
            $this->outbox,
            $this->audit,
            new PdoTransactionManager($this->pdo),
            new PaymentStateMachine(),
            $this->clock,
            new UlidIdGenerator(),
        );
    }

    private function command(): CreatePayment
    {
        return new CreatePayment(
            PaymentFixture::merchant(),
            Money::ofMinor(27500000, Currency::of('IDR')),
            PaymentMethod::qris(),
            new IdempotencyKey('create-1'),
            null,
            'Order 1234',
        );
    }

    #[Test]
    public function it_persists_the_payment_the_attempt_and_the_instruction(): void
    {
        $result = $this->handler()(new \Lumineoss\Paystart\Core\Application\Command\CreatePayment(
            PaymentFixture::merchant(),
            Money::ofMinor(27500000, Currency::of('IDR')),
            PaymentMethod::qris(),
            new IdempotencyKey('create-1'),
            null,
            'Order 1234',
        ));

        // The handler returns a view; the aggregate must be readable from the database
        // afterwards, which is what proves the save actually happened.
        $stored = $this->pdo->query('SELECT id, status, amount_minor FROM paystart_payments')->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($stored);
        self::assertSame('pending', $stored['status']);
        self::assertSame(27500000, (int) $stored['amount_minor']);

        $attempt = $this->pdo->query('SELECT gateway_reference FROM paystart_payment_attempts')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($attempt);
        self::assertSame('midtrans-order-1', $attempt['gateway_reference']);

        $instruction = $this->pdo->query('SELECT type, payload FROM paystart_payment_instructions')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($instruction);
        self::assertSame('qr_string', $instruction['type']);
        self::assertStringContainsString('0002010102', (string) $instruction['payload']);

        self::assertSame($result->id->value, $stored['id']);
    }

    /**
     * A payment that starts pending has had no status *change*, so only the instruction
     * event is emitted. Emitting a change from pending to pending would be noise the
     * consumer has to filter.
     */
    #[Test]
    public function a_pending_payment_emits_only_the_instruction_event(): void
    {
        $this->handler()($this->command());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_outbox')->fetchColumn();

        self::assertSame(1, $count);
        self::assertSame('payment.instruction_issued', $this->outbox->pending()[0]->eventName());
    }

    /**
     * A payment that comes back already settled does change status, so both events are
     * written and the status change must round-trip through the hydrator.
     */
    #[Test]
    public function a_settled_payment_emits_both_events(): void
    {
        $this->gateway->status = PaymentStatus::Settled;

        $this->handler()($this->command());

        self::assertSame(2, $this->outbox->pendingCount());
    }

    /**
     * Every event type must survive the write-then-read round trip, because a hydrator
     * that silently drops fields produces a publishable event that lies.
     */
    #[Test]
    public function every_outbox_event_round_trips_through_the_hydrator(): void
    {
        $this->gateway->status = PaymentStatus::Settled;

        $this->handler()($this->command());

        $pending = $this->outbox->pending();

        $names = array_map(static fn ($e): string => $e->eventName(), $pending);
        sort($names);

        self::assertSame(['payment.instruction_issued', 'payment.status_changed'], $names);

        foreach ($pending as $event) {
            self::assertSame($event->eventId(), $event->eventId());
            self::assertNotSame([], $event->payload());

            if ($event->eventName() === 'payment.status_changed') {
                self::assertSame(PaymentStatus::Pending->value, $event->payload()['from']);
                self::assertSame(PaymentStatus::Settled->value, $event->payload()['to']);
                self::assertSame(27500000, $event->payload()['amountMinorUnits']);
                self::assertSame('IDR', $event->payload()['currency']);
                // describe() is "kind:channel", and the hydrator is its documented
                // inverse. Asserting the reconstruction, not the string, is the real
                // contract: a method with a channel must not lose it on replay.
                self::assertSame('qr_code:qris', $event->payload()['method']);
                self::assertInstanceOf(
                    \Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentStatusChanged::class,
                    $event,
                );
                self::assertSame('qr_code', $event->method->kind->value);
                self::assertSame('qris', $event->method->channel);
            }

            if ($event->eventName() === 'payment.instruction_issued') {
                self::assertSame('qr_string', $event->payload()['instructionType']);
                self::assertFalse($event->payload()['isStatic']);
            }
        }
    }

    #[Test]
    public function outbox_events_are_read_in_append_order(): void
    {
        $this->gateway->status = PaymentStatus::Settled;

        $this->handler()($this->command());

        $pending = $this->outbox->pending();

        // The outbox sequence is insertion order, so per-aggregate ordering holds without
        // the publisher having to sort.
        self::assertSame('payment.instruction_issued', $pending[0]->eventName());
        self::assertSame('payment.status_changed', $pending[1]->eventName());
    }

    #[Test]
    public function it_writes_an_audit_entry(): void
    {
        $this->handler()($this->command());

        $row = $this->pdo->query('SELECT action, payment_id, context FROM paystart_audit_entries')->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('payment.created', $row['action']);
        self::assertIsString($row['payment_id']);
        self::assertStringStartsWith('pay_', (string) $row['payment_id']);
    }

    /**
     * The reason the outbox exists. A provider failure must undo the payment row, the
     * attempt, the instruction and the event together; a partial write would let a
     * consumer see a payment that does not exist.
     */
    #[Test]
    public function a_provider_failure_rolls_back_everything(): void
    {
        $this->gateway->failWith = new \RuntimeException('midtrans is down');

        try {
            $this->handler()($this->command());
            self::fail('expected the provider failure to propagate');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_payments')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_payment_attempts')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_payment_instructions')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_outbox')->fetchColumn(), 'no event may survive a rollback');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_audit_entries')->fetchColumn());
    }

    /**
     * The transaction manager must leave the connection usable after a rollback, or the
     * next request on the same worker fails in a confusing way.
     */
    #[Test]
    public function the_connection_is_reusable_after_a_rollback(): void
    {
        $this->gateway->failWith = new \RuntimeException('midtrans is down');

        try {
            $this->handler()($this->command());
        } catch (\RuntimeException) {
            // expected
        }

        $this->gateway->failWith = null;

        $this->handler()($this->command());

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_payments')->fetchColumn());
    }

    #[Test]
    public function the_stored_payment_is_readable_as_an_aggregate(): void
    {
        $this->handler()($this->command());

        $id = $this->pdo->query('SELECT id FROM paystart_payments')->fetchColumn();
        self::assertIsString($id);

        $payment = $this->payments->get(new \Lumineoss\Paystart\Core\Domain\Payment\PaymentId($id));

        self::assertSame(PaymentStatus::Pending, $payment->status());
        self::assertNotNull($payment->activeAttempt());
        self::assertSame(27500000, $payment->amount->minorUnits);
        self::assertSame(
            InstructionType::QrString,
            $payment->activeAttempt()?->instructions()[0]->type,
        );
    }
}

/**
 * Gateway double that returns a pending QR without any HTTP.
 */
final class StubGateway implements PaymentGatewayPort
{
    public ?\Throwable $failWith = null;

    public PaymentStatus $status = PaymentStatus::Pending;

    public function name(): string
    {
        return 'stub';
    }

    public function declare(): array
    {
        return [Capability::Charge, Capability::PartialRefund, Capability::Void, Capability::Capture];
    }

    public function supports(Capability $capability): bool
    {
        return \in_array($capability, $this->declare(), true);
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return new PaymentResult(
            new GatewayReference('midtrans-order-1'),
            $this->status,
            $request->amount,
            [new PaymentInstruction(
                InstructionType::QrString,
                new QrPayload('00020101021226670016COM.GOOGLE.PAY'),
                false,
                null,
                new GatewayReference('midtrans-order-1'),
                'qris',
            )],
        );
    }

    public function getStatus(GatewayReference $reference): GatewayStatusSnapshot
    {
        throw new \LogicException('not used');
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        throw new \LogicException('not used');
    }

    public function refund(RefundRequest $request): RefundResult
    {
        throw new \LogicException('not used');
    }

    public function cancel(CancelRequest $request): PaymentResult
    {
        throw new \LogicException('not used');
    }

    public function createSubscription(CreateSubscriptionRequest $request): SubscriptionResult
    {
        throw new \LogicException('not used');
    }

    public function disableSubscription(GatewayReference $subscriptionReference): void
    {
        throw new \LogicException('not used');
    }
}
