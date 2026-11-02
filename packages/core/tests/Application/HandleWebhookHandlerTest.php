<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Application\Handler\HandleWebhookHandler;
use Lumineoss\Paystart\Core\Domain\Audit\AuditAction;
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
use Lumineoss\Paystart\Core\Domain\Shared\FrozenClock;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdGenerator;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RawRequest;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventStatus;
use Lumineoss\Paystart\Core\Port\Webhook\Exception\InvalidWebhookSignature;
use Lumineoss\Paystart\Core\Port\Webhook\Exception\WebhookVerificationUnavailable;
use Lumineoss\Paystart\Core\Port\Webhook\VerifiedWebhook;
use Lumineoss\Paystart\Core\Tests\Application\Fake\FakeTransactionManager;
use Lumineoss\Paystart\Core\Tests\Application\Fake\FakeWebhookTranslator;
use Lumineoss\Paystart\Core\Tests\Application\Fake\FakeWebhookVerifier;
use Lumineoss\Paystart\Core\Tests\Application\Fake\InMemoryAuditRepository;
use Lumineoss\Paystart\Core\Tests\Application\Fake\InMemoryOutbox;
use Lumineoss\Paystart\Core\Tests\Application\Fake\InMemoryPaymentRepository;
use Lumineoss\Paystart\Core\Tests\Application\Fake\InMemoryWebhookInbox;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class HandleWebhookHandlerTest extends TestCase
{
    private FrozenClock $clock;

    private FakeWebhookVerifier $verifier;

    private FakeWebhookTranslator $translator;

    private InMemoryWebhookInbox $inbox;

    private InMemoryPaymentRepository $payments;

    private InMemoryOutbox $outbox;

    private InMemoryAuditRepository $audit;

    private FakeTransactionManager $transactions;

    private AbstractLogger $logger;

    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private GatewayReference $reference;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-11-02T10:00:00+00:00'));
        $this->verifier = new FakeWebhookVerifier();
        $this->translator = new FakeWebhookTranslator();
        $this->inbox = new InMemoryWebhookInbox();
        $this->payments = new InMemoryPaymentRepository();
        $this->outbox = new InMemoryOutbox();
        $this->audit = new InMemoryAuditRepository();
        $this->transactions = new FakeTransactionManager();
        $this->logs = [];

        $logs = &$this->logs;
        $this->logger = new class ($logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $this->reference = new GatewayReference('fake-ref-1');
    }

    private function handler(): HandleWebhookHandler
    {
        $ids = new class () implements IdGenerator {
            private int $n = 0;

            public function generate(string $prefix): string
            {
                $this->n++;

                return \sprintf('%s_%026d', $prefix, $this->n);
            }
        };

        return new HandleWebhookHandler(
            $this->verifier,
            $this->translator,
            $this->inbox,
            $this->payments,
            $this->outbox,
            $this->audit,
            $this->transactions,
            new PaymentStateMachine(),
            $this->clock,
            $ids,
            $this->logger,
        );
    }

    private function webhook(
        string $eventId = 'evt-1',
        PaymentStatus $status = PaymentStatus::Captured,
        ?Money $amount = null,
    ): VerifiedWebhook {
        $this->translator->status = $status;
        $this->translator->amount = $amount;

        return new VerifiedWebhook(
            'fake',
            $eventId,
            'payment.status',
            $this->reference,
            ['status' => $status->value],
            $this->clock->now(),
        );
    }

    private function request(): RawRequest
    {
        return RawRequest::create('{"status":"capture"}', ['content-type' => 'application/json'], 'POST', '/webhooks/fake');
    }

    private function pendingPayment(int $majorUnits = 275000): Payment
    {
        $payment = Payment::create(
            $this->payments->nextId(),
            new MerchantId('mer_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            Money::ofMajor($majorUnits, Currency::of('IDR')),
            PaymentMethod::qris(),
            $this->clock->now(),
        );

        $payment->startAttempt(
            Attempt::start(
                new AttemptId('att_01j9x2k4m5n6p7q8r9s0t1u2v3'),
                PaymentMethod::qris(),
                $this->clock->now(),
                $this->reference,
                [new PaymentInstruction(InstructionType::QrString, new QrPayload('GRABPAY-QR'), false)],
            ),
            $this->clock->now(),
        );

        $this->payments->save($payment);

        return $payment;
    }

    #[Test]
    public function a_valid_webhook_moves_the_payment_forward(): void
    {
        $payment = $this->pendingPayment();
        $this->verifier->result = $this->webhook();

        $response = ($this->handler())($this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame(PaymentStatus::Captured, $this->payments->get($payment->id)->status());
    }

    #[Test]
    public function a_valid_webhook_emits_a_status_change_and_an_audit_entry(): void
    {
        $this->pendingPayment();
        $this->verifier->result = $this->webhook();

        ($this->handler())($this->request());

        self::assertContains('payment.status_changed', $this->outbox->eventNames());
        self::assertContains(AuditAction::WebhookReceived->value, $this->audit->actions());
    }

    #[Test]
    public function an_invalid_signature_is_rejected_with_401_and_never_reaches_the_database(): void
    {
        $payment = $this->pendingPayment();
        $this->verifier->throw = InvalidWebhookSignature::mismatch('fake', 'digest does not match');

        $response = ($this->handler())($this->request());

        self::assertSame(401, $response->statusCode);
        self::assertTrue($response->shouldAlert);
        self::assertSame(PaymentStatus::Pending, $this->payments->get($payment->id)->status());
        self::assertSame(0, $this->transactions->begun, 'a rejected webhook must not open a transaction');
        self::assertSame([], $this->outbox->eventNames());
    }

    #[Test]
    public function a_rejected_webhook_is_audited_as_a_rejection(): void
    {
        $this->pendingPayment();
        $this->verifier->throw = InvalidWebhookSignature::mismatch('fake', 'bad');

        ($this->handler())($this->request());

        self::assertContains(AuditAction::WebhookRejected->value, $this->audit->actions());
    }

    /**
     * The fail-closed rule. A missing secret is a configuration error, and accepting
     * the webhook anyway is how a forged settlement gets recorded.
     */
    #[Test]
    public function a_missing_secret_fails_closed_with_500(): void
    {
        $payment = $this->pendingPayment();
        $this->verifier->throw = WebhookVerificationUnavailable::missingSecret('fake');

        $response = ($this->handler())($this->request());

        self::assertSame(500, $response->statusCode);
        self::assertSame(PaymentStatus::Pending, $this->payments->get($payment->id)->status());
    }

    #[Test]
    public function the_body_is_never_logged(): void
    {
        $this->pendingPayment();
        $this->verifier->throw = InvalidWebhookSignature::mismatch('fake', 'bad');

        ($this->handler())(RawRequest::create('{"card":"4111111111111111"}'));

        foreach ($this->logs as $log) {
            self::assertStringNotContainsString('4111111111111111', $log['message']);
            self::assertStringNotContainsString('4111111111111111', (string) json_encode($log['context']));
        }
    }

    #[Test]
    public function a_duplicate_delivery_is_acknowledged_without_reapplying(): void
    {
        $payment = $this->pendingPayment();
        $this->verifier->result = $this->webhook('evt-1');

        $first = ($this->handler())($this->request());
        $eventsAfterFirst = \count($this->outbox->eventNames());

        $second = ($this->handler())($this->request());

        self::assertSame(200, $first->statusCode);
        self::assertSame(200, $second->statusCode);
        self::assertStringContainsString('duplicate', $second->body);
        self::assertCount($eventsAfterFirst, $this->outbox->eventNames(), 'a duplicate must not emit a second event');
        self::assertSame(PaymentStatus::Captured, $this->payments->get($payment->id)->status());
    }

    #[Test]
    public function a_duplicate_is_audited_as_ignored(): void
    {
        $this->pendingPayment();
        $this->verifier->result = $this->webhook('evt-1');

        ($this->handler())($this->request());
        ($this->handler())($this->request());

        self::assertContains(AuditAction::WebhookDuplicateIgnored->value, $this->audit->actions());
    }

    /**
     * Midtrans explicitly documents that notifications can arrive out of order. An
     * event implying a state the payment already occupies must be a harmless no-op.
     */
    #[Test]
    public function an_out_of_order_event_implying_the_current_state_is_a_no_op(): void
    {
        $payment = $this->pendingPayment();
        $this->verifier->result = $this->webhook('evt-1', PaymentStatus::Pending);

        $response = ($this->handler())($this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame(PaymentStatus::Pending, $this->payments->get($payment->id)->status());
        self::assertSame([], $this->outbox->eventNames(), 'a no-op must not emit an event');
    }

    #[Test]
    public function a_backwards_event_is_refused_and_retained_for_replay(): void
    {
        $payment = $this->pendingPayment();
        $payment->transitionTo(PaymentStatus::Captured, new PaymentStateMachine(), $this->clock->now());
        $this->payments->save($payment);

        // A late "pending" after "captured" is the classic out-of-order case.
        $this->verifier->result = $this->webhook('evt-late', PaymentStatus::Authorized);

        $response = ($this->handler())($this->request());

        self::assertSame(500, $response->statusCode, 'the provider should retry rather than the event being lost');
        self::assertSame(PaymentStatus::Captured, $this->payments->get($payment->id)->status());

        $stored = $this->inbox->all()[0];
        self::assertSame(WebhookEventStatus::Failed, $stored->status());
    }

    #[Test]
    public function an_amount_mismatch_is_refused_rather_than_recorded(): void
    {
        $payment = $this->pendingPayment(275000);
        $this->verifier->result = $this->webhook(
            'evt-1',
            PaymentStatus::Captured,
            Money::ofMajor(1000, Currency::of('IDR')),
        );

        $response = ($this->handler())($this->request());

        self::assertSame(500, $response->statusCode);
        self::assertSame(PaymentStatus::Pending, $this->payments->get($payment->id)->status());
    }

    #[Test]
    public function a_matching_reported_amount_is_accepted(): void
    {
        $payment = $this->pendingPayment(275000);
        $this->verifier->result = $this->webhook(
            'evt-1',
            PaymentStatus::Captured,
            Money::ofMajor(275000, Currency::of('IDR')),
        );

        $response = ($this->handler())($this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame(PaymentStatus::Captured, $this->payments->get($payment->id)->status());
    }

    /**
     * A webhook for a payment created outside this library is not an error. Answering
     * non-2xx would make the provider retry forever.
     */
    #[Test]
    public function an_unknown_reference_is_recorded_and_acknowledged(): void
    {
        $this->verifier->result = $this->webhook();

        $response = ($this->handler())($this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame([], $this->outbox->eventNames());
    }

    #[Test]
    public function an_event_that_implies_no_status_is_ignored_not_failed(): void
    {
        $payment = $this->pendingPayment();
        $this->translator->category = 'refund.status';
        $this->verifier->result = $this->webhook('evt-refund', PaymentStatus::Captured);
        $this->translator->status = null;

        $response = ($this->handler())($this->request());

        self::assertSame(200, $response->statusCode);
        self::assertSame(PaymentStatus::Pending, $this->payments->get($payment->id)->status());
    }

    #[Test]
    public function the_verifier_runs_before_anything_else_reads_the_body(): void
    {
        $this->pendingPayment();
        $this->verifier->result = $this->webhook();

        ($this->handler())($this->request());

        self::assertSame(1, $this->verifier->calls);
        self::assertSame('{"status":"capture"}', $this->verifier->lastBody);
    }

    #[Test]
    public function transaction_bookkeeping_stays_balanced_on_both_paths(): void
    {
        $this->pendingPayment();
        $this->verifier->result = $this->webhook('evt-1');

        ($this->handler())($this->request());
        ($this->handler())($this->request());

        $this->transactions->assertBalanced();
        self::assertSame(1, $this->transactions->committed, 'the duplicate path must not open a transaction');
    }
}
