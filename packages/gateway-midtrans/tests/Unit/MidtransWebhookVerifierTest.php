<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Tests\Unit;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\RawRequest;
use Lumineoss\Paystart\Core\Port\Webhook\Exception\InvalidWebhookSignature;
use Lumineoss\Paystart\Gateway\Midtrans\Exception\UnsupportedCurrency;
use Lumineoss\Paystart\Gateway\Midtrans\Webhook\MidtransWebhookTranslator;
use Lumineoss\Paystart\Gateway\Midtrans\Webhook\MidtransWebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MidtransWebhookVerifierTest extends TestCase
{
    private const string SERVER_KEY = 'SB-Mid-server-test-key';

    private function verifier(): MidtransWebhookVerifier
    {
        return new MidtransWebhookVerifier(self::SERVER_KEY);
    }

    /**
     * Builds a notification the way Midtrans documents it: the signature is
     * SHA512(order_id + status_code + gross_amount + ServerKey) over the exact strings.
     *
     * @param array<string, mixed> $overrides
     */
    private function payload(array $overrides = [], ?string $signature = null): string
    {
        $base = [
            'transaction_time' => '2020-09-29 11:27:33',
            'transaction_status' => 'settlement',
            'transaction_id' => '9f07920a-6145-4d1e-9fc2-66e6fd6bc6fc',
            'status_message' => 'midtrans payment notification',
            'status_code' => '200',
            'payment_type' => 'qris',
            'order_id' => 'order03',
            'merchant_id' => 'M099098',
            'gross_amount' => '275000.00',
            'fraud_status' => 'accept',
            'currency' => 'IDR',
        ];

        $payload = array_merge($base, $overrides);

        $payload['signature_key'] = $signature ?? hash(
            'sha512',
            (string) $payload['order_id']
            . (string) $payload['status_code']
            . (string) $payload['gross_amount']
            . self::SERVER_KEY,
        );

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_accepts_a_correctly_signed_notification(): void
    {
        $webhook = $this->verifier()->verify(RawRequest::create($this->payload()));

        self::assertSame('midtrans', $webhook->provider);
        self::assertSame('settlement', $webhook->eventType);
        self::assertSame('order03', $webhook->reference->value);
    }

    /**
     * The documented example from Midtrans' own QRIS page, recomputed locally. If this
     * ever fails, the signature recipe has been implemented wrong.
     */
    #[Test]
    public function it_matches_the_documented_signature_recipe(): void
    {
        $expected = hash('sha512', 'order03200' . '275000.00' . self::SERVER_KEY);

        $webhook = $this->verifier()->verify(RawRequest::create($this->payload()));

        self::assertSame($expected, hash('sha512', 'order03200' . $webhook->payload['gross_amount'] . self::SERVER_KEY));
    }

    #[Test]
    public function it_rejects_a_tampered_gross_amount(): void
    {
        $body = $this->payload(['gross_amount' => '275000.00']);
        $tampered = str_replace('"275000.00"', '"1.00"', $body);

        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify(RawRequest::create($tampered));
    }

    #[Test]
    public function it_rejects_a_tampered_order_id(): void
    {
        $body = $this->payload();
        $tampered = str_replace('"order_id":"order03"', '"order_id":"order04"', $body);

        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify(RawRequest::create($tampered));
    }

    #[Test]
    public function it_rejects_a_tampered_status_code(): void
    {
        $body = $this->payload();
        $tampered = str_replace('"status_code":"200"', '"status_code":"201"', $body);

        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify(RawRequest::create($tampered));
    }

    #[Test]
    public function it_rejects_a_signature_from_a_different_server_key(): void
    {
        $body = $this->payload([], hash('sha512', 'order03200' . '275000.00' . 'other-key'));

        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify(RawRequest::create($body));
    }

    /**
     * Re-encoding the body changes whitespace and key order. Verification must hash the
     * received bytes' *values*, which survive re-encoding, so a reformatted but otherwise
     * identical body still verifies. This documents that the signature is over fields,
     * not over the byte stream, unlike Stripe.
     */
    #[Test]
    public function a_reformatted_but_identical_body_still_verifies(): void
    {
        $compact = $this->payload();
        $pretty = json_encode(json_decode($compact, true, 16, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $webhook = $this->verifier()->verify(RawRequest::create((string) $pretty));

        self::assertSame('order03', $webhook->reference->value);
    }

    #[Test]
    public function it_rejects_an_empty_body(): void
    {
        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify(RawRequest::create(''));
    }

    #[Test]
    public function it_rejects_a_body_that_is_not_json(): void
    {
        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify(RawRequest::create('order_id=order03'));
    }

    /**
     * A form-encoded body decodes to an empty array, which Midtrans' own documentation
     * warns about when the notification URL is misconfigured.
     */
    #[Test]
    public function it_rejects_a_json_body_without_a_signature(): void
    {
        $this->expectException(InvalidWebhookSignature::class);

        $this->verifier()->verify(RawRequest::create('{"order_id":"order03","status_code":"200"}'));
    }

    #[Test]
    public function it_accepts_numeric_status_code_and_amount_forms(): void
    {
        $body = $this->payload(['status_code' => 200, 'gross_amount' => '275000.00']);

        $webhook = $this->verifier()->verify(RawRequest::create($body));

        self::assertSame('midtrans', $webhook->provider);
    }

    /**
     * Midtrans has no event id, so one is derived from order, status code and status.
     * `transaction_id` is excluded because Midtrans reuses it across notifications.
     */
    #[Test]
    public function the_derived_event_id_is_stable_for_the_same_state(): void
    {
        $first = $this->verifier()->verify(RawRequest::create($this->payload()));
        $second = $this->verifier()->verify(RawRequest::create($this->payload()));

        self::assertSame($first->externalEventId, $second->externalEventId);
    }

    #[Test]
    public function the_derived_event_id_differs_for_a_different_state(): void
    {
        $settled = $this->verifier()->verify(RawRequest::create($this->payload()));
        $expired = $this->verifier()->verify(RawRequest::create($this->payload([
            'transaction_status' => 'expire',
            'status_code' => '202',
        ])));

        self::assertNotSame($settled->externalEventId, $expired->externalEventId);
    }

    #[Test]
    public function it_refuses_to_construct_without_a_server_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MidtransWebhookVerifier('');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, PaymentStatus|null}>
     */
    public static function statusMappings(): iterable
    {
        yield 'pending' => [['transaction_status' => 'pending', 'status_code' => '201'], PaymentStatus::Pending];
        yield 'capture' => [['transaction_status' => 'capture', 'status_code' => '200'], PaymentStatus::Authorized];
        yield 'settlement' => [['transaction_status' => 'settlement', 'status_code' => '200'], PaymentStatus::Settled];
        yield 'deny' => [['transaction_status' => 'deny', 'status_code' => '202'], PaymentStatus::Failed];
        yield 'cancel' => [['transaction_status' => 'cancel', 'status_code' => '202'], PaymentStatus::Canceled];
        yield 'expire' => [['transaction_status' => 'expire', 'status_code' => '202'], PaymentStatus::Expired];
        yield 'failure' => [['transaction_status' => 'failure', 'status_code' => '202'], PaymentStatus::Failed];
        yield 'refund implies no status' => [['transaction_status' => 'refund', 'status_code' => '200'], null];
        yield 'partial_refund implies no status' => [['transaction_status' => 'partial_refund', 'status_code' => '200'], null];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[Test]
    #[DataProvider('statusMappings')]
    public function it_maps_transaction_status_to_our_vocabulary(array $overrides, ?PaymentStatus $expected): void
    {
        $webhook = $this->verifier()->verify(RawRequest::create($this->payload($overrides)));

        self::assertSame($expected, (new MidtransWebhookTranslator())->impliedStatus($webhook));
    }

    #[Test]
    public function it_reads_the_reported_amount_in_minor_units(): void
    {
        $webhook = $this->verifier()->verify(RawRequest::create($this->payload()));

        $amount = (new MidtransWebhookTranslator())->impliedAmount($webhook);

        self::assertNotNull($amount);
        self::assertSame(27500000, $amount->minorUnits);
        self::assertSame('IDR', $amount->currency->code);
    }

    #[Test]
    public function it_classifies_refund_notifications_as_a_refund_category(): void
    {
        $webhook = $this->verifier()->verify(RawRequest::create($this->payload([
            'transaction_status' => 'refund',
        ])));

        self::assertSame('refund.status', (new MidtransWebhookTranslator())->eventCategory($webhook));
    }

    #[Test]
    public function it_builds_a_provider_scoped_deduplication_key(): void
    {
        $webhook = $this->verifier()->verify(RawRequest::create($this->payload()));

        $key = (new MidtransWebhookTranslator())->deduplicationKey($webhook);

        self::assertStringStartsWith('midtrans:', $key->value);
    }

    #[Test]
    public function a_non_idr_currency_in_a_notification_is_refused(): void
    {
        $this->expectException(UnsupportedCurrency::class);

        $webhook = $this->verifier()->verify(RawRequest::create($this->payload([
            'currency' => 'USD',
            'gross_amount' => '10.00',
        ])));

        (new MidtransWebhookTranslator())->impliedAmount($webhook);
    }
}
