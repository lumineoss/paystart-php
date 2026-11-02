<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Tests\Unit;

use Lumineoss\Paystart\Core\Domain\Payment\Capability;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\RefundStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\FrozenClock;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Port\Gateway\CancelRequest;
use Lumineoss\Paystart\Core\Port\Gateway\CreatePaymentRequest;
use Lumineoss\Paystart\Core\Port\Gateway\RefundRequest;
use Lumineoss\Paystart\Gateway\Midtrans\Amount\MidtransAmountScale;
use Lumineoss\Paystart\Gateway\Midtrans\Config\MidtransConfig;
use Lumineoss\Paystart\Gateway\Midtrans\Exception\MidtransTransactionError;
use Lumineoss\Paystart\Gateway\Midtrans\Http\MidtransHttpClient;
use Lumineoss\Paystart\Gateway\Midtrans\Mapping\PaymentMethodMapper;
use Lumineoss\Paystart\Gateway\Midtrans\MidtransGateway;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class MidtransGatewayTest extends TestCase
{
    private RecordingHttpClient $http;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-02T10:00:00+00:00'));
    }

    private function gateway(): MidtransGateway
    {
        $config = new MidtransConfig('SB-Mid-server-test-key', 'SB-Mid-client-test-key', false);

        return new MidtransGateway(
            new MidtransHttpClient($this->http, new TestRequestFactory(), new TestRequestFactory(), $config, new NullLogger()),
            new MidtransAmountScale(),
            new PaymentMethodMapper(),
            $this->clock,
        );
    }

    private function request(?PaymentMethod $method = null): CreatePaymentRequest
    {
        return new CreatePaymentRequest(
            new MerchantId('mer_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            Money::ofMinor(27500000, Currency::of('IDR')),
            $method ?? PaymentMethod::qris(),
            new IdempotencyKey('pay_01j9x2k4m5n6p7q8r9s0t1u2v3'),
        );
    }

    #[Test]
    public function it_sends_whole_rupiah_not_ledger_minor_units(): void
    {
        $this->http->queue(200, $this->chargeResponse());

        ($this->gateway())->createPayment($this->request());

        $body = $this->http->lastJsonBody();
        self::assertSame(275000, $body['transaction_details']['gross_amount'], 'Midtrans must receive 275000, not 27500000');
    }

    #[Test]
    public function it_sends_the_payment_under_the_idempotency_key_as_order_id(): void
    {
        $this->http->queue(200, $this->chargeResponse());

        ($this->gateway())->createPayment($this->request());

        self::assertSame('pay_01j9x2k4m5n6p7q8r9s0t1u2v3', $this->http->lastJsonBody()['transaction_details']['order_id']);
    }

    #[Test]
    public function it_authenticates_with_basic_auth_and_never_puts_the_key_in_the_url(): void
    {
        $this->http->queue(200, $this->chargeResponse());

        ($this->gateway())->createPayment($this->request());

        $request = $this->http->lastRequest();
        self::assertNotNull($request);
        self::assertSame(
            'Basic ' . base64_encode('SB-Mid-server-test-key:'),
            $request->getHeaderLine('Authorization'),
        );
        self::assertStringNotContainsString('SB-Mid-server-test-key', $request->getUri()->getPath());
        self::assertStringNotContainsString('SB-Mid-server-test-key', $request->getUri()->getQuery());
    }

    #[Test]
    public function it_maps_a_pending_qris_charge_to_a_qr_string_instruction(): void
    {
        $this->http->queue(200, $this->chargeResponse());

        $result = ($this->gateway())->createPayment($this->request());

        self::assertSame(PaymentStatus::Pending, $result->status);

        // The response carries both a raw qr_string and a generate-qr-code image URL.
        // Both are kept: the string can be re-rendered, the URL cannot (ADR 0004).
        $types = array_map(static fn ($i): InstructionType => $i->type, $result->instructions);
        self::assertContains(InstructionType::QrString, $types);
        self::assertContains(InstructionType::QrImageUrl, $types);
        self::assertInstanceOf(QrPayload::class, $result->instructions[0]->payload);
    }

    #[Test]
    public function it_maps_settlement_to_our_settled_status(): void
    {
        $this->http->queue(200, $this->chargeResponse(['transaction_status' => 'settlement']));

        $result = ($this->gateway())->createPayment($this->request());

        self::assertSame(PaymentStatus::Settled, $result->status);
        self::assertSame([], $result->instructions, 'a settled payment has no instruction to show');
    }

    #[Test]
    public function it_maps_capture_to_authorized_not_settled(): void
    {
        $this->http->queue(200, $this->chargeResponse(['transaction_status' => 'capture']));

        self::assertSame(PaymentStatus::Authorized, ($this->gateway())->createPayment($this->request())->status);
    }

    #[Test]
    public function a_denied_charge_reports_a_sanitised_failure_reason(): void
    {
        $this->http->queue(200, $this->chargeResponse([
            'transaction_status' => 'deny',
            'status_code' => '202',
            'status_message' => 'card 4811-1111-1111-1114 declined',
        ]));

        $result = ($this->gateway())->createPayment($this->request());

        self::assertSame(PaymentStatus::Failed, $result->status);
        self::assertSame('declined (202)', $result->failureReason);
        self::assertStringNotContainsString('4811', (string) $result->failureReason);
    }

    #[Test]
    public function it_reads_a_qr_image_url_as_a_distinct_instruction_type(): void
    {
        $this->http->queue(200, $this->chargeResponse([
            'qr_string' => null,
            'actions' => [
                ['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.midtrans.com/v2/qris/x/qr-code'],
            ],
        ]));

        $result = ($this->gateway())->createPayment($this->request());

        self::assertSame(InstructionType::QrImageUrl, $result->instructions[0]->type);
    }

    #[Test]
    public function it_maps_a_virtual_account_response(): void
    {
        $this->http->queue(200, $this->chargeResponse([
            'payment_type' => 'bank_transfer',
            'actions' => [],
            'qr_string' => null,
            'va_numbers' => [['bank' => 'bca', 'va_number' => '8578000000123456']],
        ]));

        $result = ($this->gateway())->createPayment($this->request(PaymentMethod::bankTransfer('bca')));

        self::assertSame(InstructionType::VirtualAccount, $result->instructions[0]->type);
    }

    #[Test]
    public function it_reads_a_permata_virtual_account_from_the_top_level_field(): void
    {
        $this->http->queue(200, $this->chargeResponse([
            'payment_type' => 'bank_transfer',
            'actions' => [],
            'qr_string' => null,
            'permata_va_number' => '8578000000987654',
        ]));

        $result = ($this->gateway())->createPayment($this->request(PaymentMethod::bankTransfer('permata')));

        self::assertSame(InstructionType::VirtualAccount, $result->instructions[0]->type);
    }

    #[Test]
    public function it_maps_a_retail_code_response(): void
    {
        $this->http->queue(200, $this->chargeResponse([
            'payment_type' => 'cstore',
            'actions' => [],
            'qr_string' => null,
            'payment_code' => '1234567890123456',
            'store' => 'indomaret',
        ]));

        $result = ($this->gateway())->createPayment($this->request(PaymentMethod::retailOutlet('indomaret')));

        self::assertSame(InstructionType::RetailCode, $result->instructions[0]->type);
    }

    #[Test]
    public function it_reports_a_rejected_request_rather_than_returning_a_result(): void
    {
        $this->http->queue(401, ['status_code' => '401', 'status_message' => 'Transaction cannot be authorized']);

        $this->expectException(MidtransTransactionError::class);

        ($this->gateway())->createPayment($this->request());
    }

    /**
     * Midtrans can answer HTTP 200 with a failure status_code. Treating that as success
     * would record a payment that does not exist.
     */
    #[Test]
    public function a_2xx_with_a_failure_status_code_is_still_a_rejection(): void
    {
        $this->http->queue(200, ['status_code' => '402', 'status_message' => 'denied']);

        $this->expectException(MidtransTransactionError::class);

        ($this->gateway())->createPayment($this->request());
    }

    #[Test]
    public function the_rejection_message_does_not_echo_the_provider_message(): void
    {
        $this->http->queue(200, [
            'status_code' => '402',
            'status_message' => 'card 4811-1111-1111-1114 declined',
        ]);

        try {
            ($this->gateway())->createPayment($this->request());
            self::fail('expected a rejection');
        } catch (MidtransTransactionError $e) {
            self::assertStringNotContainsString('4811', $e->getMessage());
            self::assertStringContainsString('402', $e->getMessage());
        }
    }

    #[Test]
    public function it_reads_status_for_polling(): void
    {
        $this->http->queue(200, [
            'status_code' => '200',
            'transaction_status' => 'settlement',
            'gross_amount' => '275000.00',
            'currency' => 'IDR',
            'order_id' => 'order03',
        ]);

        $snapshot = ($this->gateway())->getStatus(new GatewayReference('order03'));

        self::assertSame(PaymentStatus::Settled, $snapshot->status);
        self::assertSame(27500000, $snapshot->amount->minorUnits);
        self::assertSame('settlement', $snapshot->rawStatus);
    }

    #[Test]
    public function it_url_encodes_the_reference_in_the_status_path(): void
    {
        $this->http->queue(200, [
            'status_code' => '200',
            'transaction_status' => 'pending',
            'gross_amount' => '1.00',
            'order_id' => 'order 03/x',
        ]);

        ($this->gateway())->getStatus(new GatewayReference('order 03/x'));

        $path = $this->http->lastRequest()?->getUri()->getPath();
        self::assertIsString($path);
        self::assertStringContainsString('order%2003%2Fx', $path);
    }

    #[Test]
    public function it_sends_the_idempotency_key_as_the_refund_key(): void
    {
        $this->http->queue(200, [
            'status_code' => '200',
            'transaction_status' => 'refund',
            'order_id' => 'order03',
            'refund_key' => 'rfd_01j9x2k4m5n6p7q8r9s0t1u2v3',
            'refund_amount' => '10000.00',
            'gross_amount' => '275000.00',
            'currency' => 'IDR',
        ]);

        ($this->gateway())->refund(new RefundRequest(
            new GatewayReference('order03'),
            Money::ofMinor(1000000, Currency::of('IDR')),
            new IdempotencyKey('rfd_01j9x2k4m5n6p7q8r9s0t1u2v3'),
            'customer request',
        ));

        $body = $this->http->lastJsonBody();
        self::assertSame('rfd_01j9x2k4m5n6p7q8r9s0t1u2v3', $body['refund_key'], 'Midtrans deduplicates on refund_key');
        self::assertSame(10000, $body['amount']);
    }

    #[Test]
    public function it_maps_an_accepted_refund_to_succeeded(): void
    {
        $this->http->queue(200, [
            'status_code' => '200',
            'transaction_status' => 'refund',
            'order_id' => 'order03',
            'refund_key' => 'rfd_01j9x2k4m5n6p7q8r9s0t1u2v3',
            'gross_amount' => '275000.00',
            'currency' => 'IDR',
        ]);

        $result = ($this->gateway())->refund(new RefundRequest(
            new GatewayReference('order03'),
            Money::ofMinor(27500000, Currency::of('IDR')),
            new IdempotencyKey('rfd_01j9x2k4m5n6p7q8r9s0t1u2v3'),
        ));

        self::assertSame(RefundStatus::Succeeded, $result->status);
    }

    #[Test]
    public function it_cancels_a_pending_transaction(): void
    {
        $this->http->queue(200, $this->chargeResponse(['transaction_status' => 'cancel', 'status_code' => '202']));

        $result = ($this->gateway())->cancel(new CancelRequest(
            new GatewayReference('order03'),
            new IdempotencyKey('cancel-1'),
        ));

        self::assertSame(PaymentStatus::Canceled, $result->status);
    }

    #[Test]
    public function it_declares_the_capabilities_it_implements(): void
    {
        $gateway = $this->gateway();

        self::assertTrue($gateway->supports(Capability::Charge));
        self::assertTrue($gateway->supports(Capability::PartialRefund));
        self::assertContains(Capability::Charge, $gateway->declare());
    }

    /**
     * A capability that is declared but not implemented would be worse than one that is
     * absent, so this path must throw explicitly.
     */
    #[Test]
    public function an_undeclared_capability_is_refused(): void
    {
        $gateway = new MidtransGateway(
            new MidtransHttpClient(
                $this->http,
                new TestRequestFactory(),
                new TestRequestFactory(),
                new MidtransConfig('key', 'client', false),
                new NullLogger(),
            ),
            new MidtransAmountScale(),
            new PaymentMethodMapper(),
            $this->clock,
            [Capability::Charge],
        );

        $this->expectException(UnsupportedCapability::class);

        $gateway->refund(new RefundRequest(
            new GatewayReference('order03'),
            Money::ofMinor(1000, Currency::of('IDR')),
            new IdempotencyKey('refund-1'),
        ));
    }

    #[Test]
    public function it_reports_a_non_idr_charge_without_calling_midtrans(): void
    {
        $this->expectException(\Lumineoss\Paystart\Gateway\Midtrans\Exception\UnsupportedCurrency::class);

        try {
            ($this->gateway())->createPayment(new CreatePaymentRequest(
                new MerchantId('mer_01j9x2k4m5n6p7q8r9s0t1u2v3'),
                Money::ofMinor(1000, Currency::of('USD')),
                PaymentMethod::qris(),
                new IdempotencyKey('k'),
            ));
        } finally {
            self::assertCount(0, $this->http->requests, 'an unsupported currency must not reach the provider');
        }
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function chargeResponse(array $overrides = []): array
    {
        return array_merge([
            'status_code' => '201',
            'status_message' => 'QRIS transaction is created',
            'transaction_id' => '0d8178e1-c6c7-4ab4-81a6-893be9d924ab',
            'order_id' => 'pay_01j9x2k4m5n6p7q8r9s0t1u2v3',
            'merchant_id' => 'M099098',
            'gross_amount' => '275000.00',
            'currency' => 'IDR',
            'payment_type' => 'qris',
            'transaction_time' => '2020-09-29 11:46:13',
            'transaction_status' => 'pending',
            'fraud_status' => 'accept',
            'qr_string' => '00020101021226670016COM.GOOGLE.PAY',
            'actions' => [
                ['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.midtrans.com/v2/qris/x/qr-code'],
            ],
        ], $overrides);
    }
}

/**
 * PSR-18 double that records every request and replays queued responses.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<array{int, array<string, mixed>}> */
    private array $queue = [];

    /**
     * @param array<string, mixed> $body
     */
    public function queue(int $status, array $body): void
    {
        $this->queue[] = [$status, $body];
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new \LogicException('No queued response for the request.');
        }

        [$status, $body] = array_shift($this->queue);

        $encoded = json_encode($body, JSON_THROW_ON_ERROR);

        return (new TestRequestFactory())
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new TestRequestFactory())->createStream($encoded));
    }

    public function lastRequest(): ?RequestInterface
    {
        return $this->requests === [] ? null : $this->requests[\count($this->requests) - 1];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastJsonBody(): array
    {
        $body = (string) $this->lastRequest()?->getBody();
        $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \LogicException('Request body was not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
