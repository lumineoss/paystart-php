<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Tests\Unit;

use Lumineoss\Paystart\Bridge\Standalone\Persistence\PaymentHydrator;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\PdoPaymentRepository;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\UlidIdGenerator;
use Lumineoss\Paystart\Bridge\Standalone\Tests\Support\PaymentFixture;
use Lumineoss\Paystart\Bridge\Standalone\Tests\Support\SqliteDatabase;
use Lumineoss\Paystart\Core\Domain\Payment\Attempt;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptId;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptStatus;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\LinkPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrFormat;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\RetailCodePayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\VirtualAccountPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\Refund;
use Lumineoss\Paystart\Core\Domain\Payment\RefundStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\ConcurrencyConflict;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdoPaymentRepositoryTest extends TestCase
{
    private PDO $pdo;

    private PdoPaymentRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::create();
        $this->repository = new PdoPaymentRepository(
            $this->pdo,
            new PaymentHydrator(),
            new UlidIdGenerator(),
        );
    }

    #[Test]
    public function it_round_trips_a_pending_payment(): void
    {
        $payment = PaymentFixture::pending();
        $this->repository->save($payment);

        $loaded = $this->repository->get($payment->id);

        self::assertSame($payment->id->value, $loaded->id->value);
        self::assertSame($payment->status(), $loaded->status());
        self::assertSame(27500000, $loaded->amount->minorUnits);
        self::assertSame('IDR', $loaded->amount->currency->code);
        self::assertSame('qr_code', $loaded->method->kind->value);
    }

    #[Test]
    public function it_round_trips_the_amount_in_minor_units(): void
    {
        $payment = PaymentFixture::pending();
        $this->repository->save($payment);

        $stored = $this->pdo
            ->query('SELECT amount_minor FROM paystart_payments')
            ->fetchColumn();

        // The ledger stores minor units; the provider conversion happens at the adapter.
        self::assertSame(27500000, (int) $stored);
    }

    #[Test]
    public function it_returns_null_for_an_unknown_id(): void
    {
        self::assertNull($this->repository->find(new PaymentId('pay_01j9x2k4m5n6p7q8r9s0t1u2v9')));
    }

    #[Test]
    public function get_throws_for_an_unknown_id(): void
    {
        $this->expectException(NotFound::class);

        $this->repository->get(new PaymentId('pay_01j9x2k4m5n6p7q8r9s0t1u2v9'));
    }

    #[Test]
    public function it_round_trips_an_attempt_and_its_instructions(): void
    {
        $payment = PaymentFixture::pending();
        $payment->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());
        $this->repository->save($payment);

        $loaded = $this->repository->get($payment->id);

        self::assertCount(1, $loaded->attempts());

        $attempt = $loaded->attempts()[0];
        self::assertSame('att_01j9x2k4m5n6p7q8r9s0t1u2v3', $attempt->id->value);
        self::assertSame(AttemptStatus::AwaitingAction, $attempt->status());
        self::assertSame('midtrans-order-1', $attempt->gatewayReference?->value);

        self::assertCount(1, $attempt->instructions());
        $instruction = $attempt->instructions()[0];
        self::assertSame(InstructionType::QrString, $instruction->type);

        $payload = $instruction->payload;
        self::assertInstanceOf(QrPayload::class, $payload);
        self::assertSame('00020101021226670016COM.GOOGLE.PAY', $payload->content);
        self::assertSame(QrFormat::String, $payload->format);
    }

    #[Test]
    public function it_preserves_instruction_expiry_and_reference(): void
    {
        $payment = PaymentFixture::pending();
        $payment->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());
        $this->repository->save($payment);

        $instruction = $this->repository->get($payment->id)->attempts()[0]->instructions()[0];

        self::assertNotNull($instruction->expiresAt);
        self::assertSame('2026-11-02T10:15:00+00:00', $instruction->expiresAt->format(DATE_ATOM));
        self::assertSame('midtrans-order-1', $instruction->gatewayReference?->value);
        self::assertSame('qris', $instruction->rawProviderType);
        self::assertFalse($instruction->isStatic);
    }

    /**
     * Each payload type has its own columns in the encoded form; a generic array would
     * silently drop fields and the payer would be shown the wrong instruction.
     */
    #[Test]
    public function it_round_trips_every_payload_type(): void
    {
        $payment = PaymentFixture::pending();

        $instructions = [
            new PaymentInstruction(InstructionType::QrImageUrl, QrPayload::fromImageUrl('https://api.midtrans.com/qr.png')),
            new PaymentInstruction(InstructionType::RedirectUrl, new LinkPayload('https://pay.example.com/x', true)),
            new PaymentInstruction(InstructionType::VirtualAccount, new VirtualAccountPayload('bca', '8578000000123456', 'PT Contoh')),
            new PaymentInstruction(InstructionType::RetailCode, new RetailCodePayload('indomaret', '1234567890123456')),
        ];

        $payment->startAttempt(
            Attempt::start(
                new AttemptId('att_01j9x2k4m5n6p7q8r9s0t1u2v3'),
                \Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod::qris(),
                PaymentFixture::time(),
                new GatewayReference('ref-1'),
                $instructions,
            ),
            PaymentFixture::time(),
        );

        $this->repository->save($payment);
        $loaded = $this->repository->get($payment->id)->attempts()[0]->instructions();

        self::assertCount(4, $loaded);

        self::assertInstanceOf(QrPayload::class, $loaded[0]->payload);
        self::assertTrue($loaded[0]->payload->isImageUrl());
        self::assertSame('https://api.midtrans.com/qr.png', $loaded[0]->payload->content);

        self::assertInstanceOf(LinkPayload::class, $loaded[1]->payload);
        self::assertSame('https://pay.example.com/x', $loaded[1]->payload->url);
        self::assertTrue($loaded[1]->payload->singleUse);

        self::assertInstanceOf(VirtualAccountPayload::class, $loaded[2]->payload);
        self::assertSame('8578000000123456', $loaded[2]->payload->number);
        self::assertSame('PT Contoh', $loaded[2]->payload->accountName);

        self::assertInstanceOf(RetailCodePayload::class, $loaded[3]->payload);
        self::assertSame('1234567890123456', $loaded[3]->payload->code);
    }

    #[Test]
    public function it_resolves_a_payment_by_gateway_reference(): void
    {
        $payment = PaymentFixture::pending();
        $payment->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());
        $this->repository->save($payment);

        $found = $this->repository->findByGatewayReference(new GatewayReference('midtrans-order-1'));

        self::assertNotNull($found);
        self::assertSame($payment->id->value, $found->id->value);
    }

    #[Test]
    public function an_unknown_gateway_reference_resolves_to_nothing(): void
    {
        self::assertNull($this->repository->findByGatewayReference(new GatewayReference('nope')));
    }

    #[Test]
    public function it_persists_a_status_transition(): void
    {
        $payment = PaymentFixture::pending();
        $payment->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());
        $this->repository->save($payment);

        $payment->transitionTo(PaymentStatus::Captured, new PaymentStateMachine(), PaymentFixture::time());
        $this->repository->save($payment);

        self::assertSame(PaymentStatus::Captured, $this->repository->get($payment->id)->status());
    }

    #[Test]
    public function it_increments_the_stored_version_on_each_save(): void
    {
        $payment = PaymentFixture::pending();
        $this->repository->save($payment);

        $payment->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());
        $this->repository->save($payment);

        $version = $this->pdo->query('SELECT version FROM paystart_payments')->fetchColumn();
        self::assertSame(1, (int) $version);
    }

    /**
     * The optimistic concurrency guarantee. Two objects loaded from the same row both
     * try to save; the second must fail rather than overwrite the first.
     */
    #[Test]
    public function a_stale_write_is_refused(): void
    {
        $payment = PaymentFixture::pending();
        $this->repository->save($payment);

        $first = $this->repository->get($payment->id);
        $second = $this->repository->get($payment->id);

        $first->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());
        $this->repository->save($first);

        // `second` still carries the version it was loaded with.
        $second->transitionTo(PaymentStatus::Canceled, new PaymentStateMachine(), PaymentFixture::time());

        $this->expectException(ConcurrencyConflict::class);

        $this->repository->save($second);
    }

    #[Test]
    public function a_refused_write_leaves_the_stored_state_untouched(): void
    {
        $payment = PaymentFixture::pending();
        $this->repository->save($payment);

        $first = $this->repository->get($payment->id);
        $second = $this->repository->get($payment->id);

        $first->transitionTo(PaymentStatus::Canceled, new PaymentStateMachine(), PaymentFixture::time());
        $this->repository->save($first);

        $second->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());

        try {
            $this->repository->save($second);
            self::fail('expected the stale write to be refused');
        } catch (ConcurrencyConflict) {
            // expected
        }

        self::assertSame(PaymentStatus::Canceled, $this->repository->get($payment->id)->status());
        self::assertCount(0, $this->repository->get($payment->id)->attempts());
    }

    #[Test]
    public function it_round_trips_a_refund(): void
    {
        $payment = PaymentFixture::captured();
        $this->repository->save($payment);

        $refund = new Refund(
            new RefundId('rfd_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            Money::ofMinor(1000000, Currency::of('IDR')),
            new IdempotencyKey('rfd_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            'customer request',
            new GatewayReference('midtrans-order-1'),
        );

        $payment->registerRefund($refund, PaymentFixture::time());
        $refund->markSucceeded();
        $payment->transitionTo(PaymentStatus::PartiallyRefunded, new PaymentStateMachine(), PaymentFixture::time());
        $this->repository->save($payment);

        $loaded = $this->repository->get($payment->id);

        self::assertCount(1, $loaded->refunds());
        self::assertSame(1000000, $loaded->refunds()[0]->amount->minorUnits);
        self::assertSame(RefundStatus::Succeeded, $loaded->refunds()[0]->status());
        self::assertSame('rfd_01j9x2k4m5n6p7q8r9s0t1u2v3', $loaded->refunds()[0]->idempotencyKey->value);
        self::assertSame(1000000, $loaded->totalRefunded()->minorUnits);
    }

    /**
     * The database, not the handler, is what makes a retried refund impossible to
     * record twice.
     */
    #[Test]
    public function the_schema_refuses_two_refunds_with_the_same_idempotency_key(): void
    {
        $payment = PaymentFixture::captured();
        $this->repository->save($payment);

        $insert = $this->pdo->prepare(
            'INSERT INTO paystart_payment_refunds
                (id, payment_id, position, currency, amount_minor, status, idempotency_key, reason, gateway_reference, created_at)
             VALUES (:id, :payment_id, :position, :currency, :amount, :status, :key, NULL, NULL, :created_at)',
        );

        $insert->execute([
            'id' => 'rfd_01j9x2k4m5n6p7q8r9s0t1u2v1',
            'payment_id' => $payment->id->value,
            'position' => 0,
            'currency' => 'IDR',
            'amount' => 1000,
            'status' => 'succeeded',
            'key' => 'same-key',
            'created_at' => '2026-11-02 10:00:00',
        ]);

        $this->expectException(\PDOException::class);

        $insert->execute([
            'id' => 'rfd_01j9x2k4m5n6p7q8r9s0t1u2v2',
            'payment_id' => $payment->id->value,
            'position' => 1,
            'currency' => 'IDR',
            'amount' => 1000,
            'status' => 'succeeded',
            'key' => 'same-key',
            'created_at' => '2026-11-02 10:00:00',
        ]);
    }

    #[Test]
    public function a_foreign_key_violation_is_not_silently_accepted(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO paystart_payment_attempts
                (id, payment_id, position, method_kind, method_channel, status, gateway_reference, failure_reason, started_at)
             VALUES (:id, :payment_id, 0, :kind, NULL, :status, NULL, NULL, :started_at)',
        );

        $this->expectException(\PDOException::class);

        $insert->execute([
            'id' => 'att_01j9x2k4m5n6p7q8r9s0t1u2v9',
            'payment_id' => 'pay_does_not_exist',
            'kind' => 'qr_code',
            'status' => 'awaiting_action',
            'started_at' => '2026-11-02 10:00:00',
        ]);
    }

    #[Test]
    public function saving_the_same_payment_twice_does_not_duplicate_attempts(): void
    {
        $payment = PaymentFixture::pending();
        $payment->startAttempt(PaymentFixture::attempt(), PaymentFixture::time());

        $this->repository->save($payment);
        $payment->transitionTo(PaymentStatus::Captured, new PaymentStateMachine(), PaymentFixture::time());
        $this->repository->save($payment);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_payment_attempts')->fetchColumn();
        self::assertSame(1, $count);

        $instructionCount = (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_payment_instructions')->fetchColumn();
        self::assertSame(1, $instructionCount, 'instructions are immutable and must not be rewritten');
    }

    #[Test]
    public function it_stores_timestamps_as_utc_regardless_of_the_input_offset(): void
    {
        $payment = Payment::create(
            new PaymentId('pay_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            PaymentFixture::merchant(),
            PaymentFixture::idr(1000),
            \Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod::qris(),
            // 10:00 in Jakarta (UTC+7) is 03:00 UTC.
            new \DateTimeImmutable('2026-11-02T10:00:00+07:00'),
        );

        $this->repository->save($payment);

        $stored = $this->pdo->query('SELECT created_at FROM paystart_payments')->fetchColumn();
        self::assertSame('2026-11-02 03:00:00', $stored);

        // And it comes back as the same instant, not shifted.
        $loaded = $this->repository->get($payment->id);
        self::assertSame(
            $payment->meta()->createdAt->getTimestamp(),
            $loaded->meta()->createdAt->getTimestamp(),
        );
    }

    #[Test]
    public function next_id_produces_a_value_the_domain_accepts(): void
    {
        $id = $this->repository->nextId();

        self::assertStringStartsWith('pay_', $id->value);
        self::assertMatchesRegularExpression('/\Apay_[0-9a-z]{8,64}\z/', $id->value);
    }
}
