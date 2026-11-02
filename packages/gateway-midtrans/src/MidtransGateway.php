<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans;

use Lumineoss\Paystart\Core\Domain\Payment\Capability;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\LinkPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\RetailCodePayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\VirtualAccountPayload;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentResult;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\RefundResult;
use Lumineoss\Paystart\Core\Domain\Payment\RefundStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Clock;
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
use Lumineoss\Paystart\Gateway\Midtrans\Amount\MidtransAmountScale;
use Lumineoss\Paystart\Gateway\Midtrans\Exception\MidtransResponseMalformed;
use Lumineoss\Paystart\Gateway\Midtrans\Http\MidtransHttpClient;
use Lumineoss\Paystart\Gateway\Midtrans\Mapping\PaymentMethodMapper;

/**
 * Midtrans Core API adapter.
 *
 * The anti-corruption boundary. Everything Midtrans-shaped stops here: the flat
 * `payment_type` + nested-channel object becomes a PaymentMethod, `actions` becomes
 * PaymentInstruction, `transaction_status` becomes PaymentStatus, and `order_id`
 * becomes a GatewayReference. Nothing above this class knows Midtrans exists.
 *
 * Design decisions worth stating because they are not obvious:
 *   - `order_id` is our payment identifier, so a webhook can be routed by reference
 *     without a provider-side lookup table.
 *   - The QR string is read from the top-level `qr_string` field when present,
 *     otherwise from the `generate-qr-code` action URL, which is an image URL and is
 *     therefore a different instruction type (ADR 0004).
 *   - Refunds use `refund_key` derived from our idempotency key, because Midtrans
 *     deduplicates on it. Without that, a retry is a second refund.
 */
final readonly class MidtransGateway implements PaymentGatewayPort
{
    private const NAME = 'midtrans';

    /**
     * @param list<Capability> $capabilities overridable because an account may not have
     *        every method enabled; the default is what the API documents as supported
     */
    public function __construct(
        private MidtransHttpClient $http,
        private MidtransAmountScale $amountScale,
        private PaymentMethodMapper $methods,
        private Clock $clock,
        private array $capabilities = [
            Capability::Charge,
            Capability::Capture,
            Capability::Void,
            Capability::PartialRefund,
            Capability::QrCode,
            Capability::PaymentLink,
            Capability::Deeplink,
            Capability::VirtualAccount,
            Capability::RetailCode,
            Capability::ThreeDsChallenge,
            Capability::Recurring,
            Capability::Installment,
        ],
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(Capability $capability): bool
    {
        return \in_array($capability, $this->capabilities, true);
    }

    /**
     * @return list<Capability>
     */
    public function declare(): array
    {
        return $this->capabilities;
    }

    public function createPayment(CreatePaymentRequest $request): PaymentResult
    {
        $this->assertSupports(Capability::Charge);

        $gross = $this->amountScale->toGateway($request->amount);

        $body = [
            'transaction_details' => [
                // Our identifier doubles as order_id so a notification can be routed
                // back without a lookup table.
                'order_id' => $request->metadata['order_id'] ?? $this->orderIdFrom($request),
                'gross_amount' => $gross->toInt(),
            ],
        ] + $this->methods->toChargeBody($request->method);

        if ($request->customer !== null) {
            $body['customer_details'] = ['customer_id' => $request->customer->value];
        }

        // `custom_expiry` only accepts whole minutes.
        if ($request->expiresAt !== null) {
            $minutes = max(1, (int) ceil(($request->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp()) / 60));
            $body['custom_expiry'] = ['expiry_duration' => $minutes, 'unit' => 'minute'];
        }

        $response = $this->http->post('/v2/charge', $body);

        return $this->toResult($response, $request->amount);
    }

    public function getStatus(GatewayReference $reference): GatewayStatusSnapshot
    {
        $response = $this->http->get('/v2/' . rawurlencode($reference->value) . '/status');

        $status = $this->mapStatus($this->requireString($response, 'transaction_status'));

        $gross = $this->requireString($response, 'gross_amount');
        $currency = $response['currency'] ?? 'IDR';
        $currency = \is_string($currency) ? $currency : 'IDR';

        return new GatewayStatusSnapshot(
            reference: $reference,
            status: $status,
            amount: $this->amountScale->fromDecimalString($gross, Currency::of($currency)),
            observedAt: $this->clock->now(),
            rawStatus: $this->requireString($response, 'transaction_status'),
        );
    }

    public function capture(CaptureRequest $request): PaymentResult
    {
        $this->assertSupports(Capability::Capture);

        // Midtrans captures by charging with the same order_id; there is no separate
        // capture endpoint in the Core API.
        $response = $this->http->post('/v2/capture', [
            'transaction_id' => $request->reference->value,
            'gross_amount' => $this->amountScale->toGateway($request->amount)->toInt(),
        ]);

        return $this->toResult($response, $request->amount);
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $this->assertSupports(Capability::PartialRefund);

        $response = $this->http->post(
            '/v2/' . rawurlencode($request->reference->value) . '/refund',
            [
                'refund_key' => $this->refundKey($request),
                'amount' => $this->amountScale->toGateway($request->amount)->toInt(),
                'reason' => $request->reason ?? 'Refund',
            ],
        );

        $currency = $response['currency'] ?? 'IDR';
        $currency = \is_string($currency) ? $currency : 'IDR';

        // Midtrans answers 200 for an accepted refund and reports the resulting status.
        // Anything it reports as a refund status other than success is left pending,
        // because a refund that Midtrans accepted but has not settled is not yet money
        // returned.
        $refundStatus = $this->mapRefundStatus($response);

        $refundKey = $this->requireString($response, 'refund_key');

        // Midtrans echoes back the refund_key we sent, which is our idempotency key and
        // therefore already carries the rfd_ prefix. A key Midtrans generated itself (it
        // does so when refund_key is omitted) has no such prefix and cannot become a
        // RefundId; the caller supplied the key, so this is a contract violation rather
        // than a value to coerce, and inventing an id would break the link between the
        // refund we recorded and the one Midtrans holds.
        $refundId = str_starts_with($refundKey, RefundId::prefix() . '_')
            ? new RefundId($refundKey)
            : throw MidtransResponseMalformed::unexpectedRefundKey($refundKey);

        return new RefundResult(
            $refundId,
            $request->reference,
            $request->amount,
            $refundStatus,
        );
    }

    public function cancel(CancelRequest $request): PaymentResult
    {
        $this->assertSupports(Capability::Void);

        $response = $this->http->post('/v2/' . rawurlencode($request->reference->value) . '/cancel');

        return $this->toResult($response, $this->amountFrom($response));
    }

    public function createSubscription(CreateSubscriptionRequest $request): SubscriptionResult
    {
        $this->assertSupports(Capability::Recurring);

        throw UnsupportedCapability::on(self::NAME, Capability::Recurring);
    }

    public function disableSubscription(GatewayReference $subscriptionReference): void
    {
        $this->assertSupports(Capability::Recurring);

        throw UnsupportedCapability::on(self::NAME, Capability::Recurring);
    }

    /**
     * Maps a charge or status response onto our PaymentResult.
     *
     * @param array<string, mixed> $response
     */
    private function toResult(array $response, Money $amount): PaymentResult
    {
        $rawStatus = $this->requireString($response, 'transaction_status');

        return new PaymentResult(
            reference: new GatewayReference($this->requireString($response, 'order_id')),
            status: $this->mapStatus($rawStatus),
            amount: $amount,
            instructions: $this->instructions($response, $rawStatus),
            failureReason: $rawStatus === 'deny' || $rawStatus === 'failure'
                ? $this->sanitisedFailureReason($response)
                : null,
        );
    }

    /**
     * Extracts payer-facing instructions from a charge response.
     *
     * Midtrans returns `actions` with assorted names, plus method-specific top-level
     * fields. A QR can arrive as a raw string (`qr_string`) or only as an image URL; the
     * two are different instruction types and must not be conflated, because one can be
     * re-rendered and the other cannot.
     *
     * @param array<string, mixed> $response
     *
     * @return list<PaymentInstruction>
     */
    private function instructions(array $response, string $rawStatus): array
    {
        if ($rawStatus !== 'pending') {
            return [];
        }

        $instructions = [];

        $qrString = $response['qr_string'] ?? null;

        if (\is_string($qrString) && trim($qrString) !== '') {
            $instructions[] = new PaymentInstruction(
                InstructionType::QrString,
                new QrPayload($qrString),
                false,
                null,
                null,
                'qris',
            );
        }

        foreach ($this->actions($response) as $action) {
            $name = $action['name'] ?? null;
            $url = $action['url'] ?? null;

            if (!\is_string($name) || !\is_string($url)) {
                continue;
            }

            $instruction = match ($name) {
                // An image URL is not a QR string: it cannot be re-rendered, and the
                // renderer must not attempt to (ADR 0004).
                'generate-qr-code', 'generate-qr-code-v2' => new PaymentInstruction(
                    InstructionType::QrImageUrl,
                    QrPayload::fromImageUrl($url),
                ),
                'deeplink-redirect' => new PaymentInstruction(
                    InstructionType::Deeplink,
                    new LinkPayload($url, true, 'mobile'),
                ),
                default => null,
            };

            if ($instruction !== null) {
                $instructions[] = $instruction;
            }
        }

        $vaNumber = null;
        $vaBank = null;

        $vaNumbers = $response['va_numbers'] ?? null;

        if (\is_array($vaNumbers) && isset($vaNumbers[0]) && \is_array($vaNumbers[0])) {
            $first = $vaNumbers[0];
            $vaNumber = \is_string($first['va_number'] ?? null) ? $first['va_number'] : null;
            $vaBank = \is_string($first['bank'] ?? null) ? $first['bank'] : null;
        }

        if ($vaNumber !== null && $vaBank !== null) {
            $instructions[] = new PaymentInstruction(
                InstructionType::VirtualAccount,
                new VirtualAccountPayload($vaBank, $vaNumber),
            );
        }

        // Permata and Mandiri return their VA at the top level rather than in va_numbers.
        $permataVa = $response['permata_va_number'] ?? null;

        if (\is_string($permataVa)) {
            $instructions[] = new PaymentInstruction(
                InstructionType::VirtualAccount,
                new VirtualAccountPayload('permata', $permataVa),
            );
        }

        $billKey = $response['bill_key'] ?? null;
        $billerCode = $response['biller_code'] ?? null;

        if (\is_string($billKey) && \is_string($billerCode)) {
            $instructions[] = new PaymentInstruction(
                InstructionType::VirtualAccount,
                new VirtualAccountPayload('mandiri', $billKey),
            );
        }

        $paymentCode = $response['payment_code'] ?? null;

        if (\is_string($paymentCode)) {
            $store = $response['store'] ?? 'indomaret';
            $instructions[] = new PaymentInstruction(
                InstructionType::RetailCode,
                new RetailCodePayload(\is_string($store) ? $store : 'indomaret', $paymentCode),
            );
        }

        return $instructions;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return list<array<string, mixed>>
     */
    private function actions(array $response): array
    {
        $actions = $response['actions'] ?? [];

        if (!\is_array($actions)) {
            return [];
        }

        /** @var list<array<string, mixed>> $normalised */
        $normalised = array_values(array_filter($actions, 'is_array'));

        return $normalised;
    }

    private function mapStatus(string $rawStatus): PaymentStatus
    {
        return match ($rawStatus) {
            'pending' => PaymentStatus::Pending,
            'capture' => PaymentStatus::Authorized,
            'settlement' => PaymentStatus::Settled,
            'deny', 'failure' => PaymentStatus::Failed,
            'cancel' => PaymentStatus::Canceled,
            'expire' => PaymentStatus::Expired,
            'refund' => PaymentStatus::Refunded,
            'partial_refund' => PaymentStatus::PartiallyRefunded,
            'chargeback', 'partial_chargeback' => PaymentStatus::Failed,
            default => throw MidtransResponseMalformed::unknownStatus($rawStatus),
        };
    }

    /**
     * @param array<string, mixed> $response
     */
    private function mapRefundStatus(array $response): RefundStatus
    {
        $status = $response['transaction_status'] ?? null;

        return match (true) {
            $status === 'refund' || $status === 'partial_refund' => RefundStatus::Succeeded,
            // Midtrans answers 200 with `pending` while the bank processes the refund.
            $status === 'pending' => RefundStatus::Pending,
            default => RefundStatus::Pending,
        };
    }

    /**
     * `refund_key` is how Midtrans deduplicates refunds.
     *
     * Our idempotency key already encodes "this exact refund request", so it is passed
     * through rather than regenerated. A random key here would turn a retry into a
     * second refund.
     */
    private function refundKey(RefundRequest $request): string
    {
        return $request->idempotencyKey->value;
    }

    private function orderIdFrom(CreatePaymentRequest $request): string
    {
        // Midtrans requires order_id to be unique per merchant and to be URL-safe, since
        // it is used as a path segment in every later call.
        return $request->idempotencyKey->value;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function amountFrom(array $response): Money
    {
        $gross = $this->requireString($response, 'gross_amount');
        $currency = $response['currency'] ?? 'IDR';

        return $this->amountScale->fromDecimalString($gross, Currency::of(\is_string($currency) ? $currency : 'IDR'));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function requireString(array $response, string $key): string
    {
        $value = $response[$key] ?? null;

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        throw MidtransResponseMalformed::missingField(self::NAME, $key);
    }

    /**
     * Midtrans' `status_message` is safe to keep only in the narrow case of a decline,
     * and even then it is replaced by a fixed code so no provider text reaches a log.
     *
     * @param array<string, mixed> $response
     */
    private function sanitisedFailureReason(array $response): string
    {
        $code = $response['status_code'] ?? null;

        return 'declined (' . (\is_string($code) ? $code : 'unknown') . ')';
    }

    private function assertSupports(Capability $capability): void
    {
        if (!$this->supports($capability)) {
            throw UnsupportedCapability::on(self::NAME, $capability);
        }
    }
}
