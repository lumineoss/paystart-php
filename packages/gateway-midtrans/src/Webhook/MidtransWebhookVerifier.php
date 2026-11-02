<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\RawRequest;
use Lumineoss\Paystart\Core\Port\Webhook\Exception\InvalidWebhookSignature;
use Lumineoss\Paystart\Core\Port\Webhook\VerifiedWebhook;
use Lumineoss\Paystart\Core\Port\Webhook\WebhookVerifierPort;

/**
 * Verifies a Midtrans HTTP notification.
 *
 * Midtrans signs with `signature_key = SHA512(order_id + status_code + gross_amount +
 * ServerKey)`, computed over four values taken from the body itself. Two consequences
 * are easy to miss and both are load-bearing here:
 *
 *   1. `gross_amount` must be hashed exactly as received. Midtrans sends "275000.00" in
 *      a notification while the charge request used 275000; normalising the string
 *      changes the digest and rejects every genuine notification.
 *   2. There is no timestamp in the signed material, so Midtrans has no replay window.
 *      Replay is therefore handled downstream by the inbox deduplication key rather
 *      than here, and this verifier cannot pretend otherwise.
 *
 * The body is parsed only to obtain those four fields. Parsing before verification
 * would be harmless here because nothing is trusted until the digest matches, but the
 * digest is computed over the raw string values from the JSON, never over re-encoded
 * data.
 */
final readonly class MidtransWebhookVerifier implements WebhookVerifierPort
{
    public function __construct(
        private string $serverKey,
    ) {
        if (trim($serverKey) === '') {
            throw InvalidArgument::because('Midtrans webhook verification requires a server key.');
        }
    }

    public function verify(RawRequest $request): VerifiedWebhook
    {
        $payload = $this->decode($request->body);

        $orderId = $this->requireString($payload, 'order_id');
        $statusCode = $this->requireString($payload, 'status_code');
        $grossAmount = $this->requireString($payload, 'gross_amount');
        $signature = $this->requireString($payload, 'signature_key');

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey);

        // hash_equals on the hex digests: constant-time, so a timing oracle cannot be
        // used to discover the correct signature one character at a time.
        if (!hash_equals($expected, $signature)) {
            throw InvalidWebhookSignature::mismatch('midtrans', 'signature_key does not match the computed digest');
        }

        return new VerifiedWebhook(
            provider: 'midtrans',
            externalEventId: $this->externalEventId(
                $orderId,
                $statusCode,
                $this->requireString($payload, 'transaction_status'),
            ),
            eventType: $this->requireString($payload, 'transaction_status'),
            reference: new GatewayReference($orderId),
            payload: $payload,
            receivedAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            rawBody: $request->body,
        );
    }

    /**
     * Midtrans has no event id, so one is derived.
     *
     * `order_id` + `status_code` + `transaction_status` identifies a genuine state
     * notification. A true re-send of the same state maps to the same key and is
     * collapsed as a duplicate, which is correct: re-applying a same-state transition
     * is a no-op anyway. `transaction_id` is deliberately not included, because
     * Midtrans reuses it across notifications for the same transaction and including it
     * would make every notification look distinct.
     */
    private function externalEventId(string $orderId, string $statusCode, string $transactionStatus): string
    {
        return \sprintf('%s:%s:%s', $orderId, $statusCode, $transactionStatus);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        if (trim($body) === '') {
            // Midtrans sends an empty body when the notification URL is misconfigured,
            // so this is a distinct, diagnosable failure rather than a generic one.
            throw InvalidWebhookSignature::mismatch('midtrans', 'body is empty; the notification URL may be misconfigured');
        }

        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidWebhookSignature::mismatch('midtrans', 'body is not valid JSON');
        }

        if (!\is_array($decoded)) {
            throw InvalidWebhookSignature::mismatch('midtrans', 'body is not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        // Midtrans sends some of these as numbers in some fields and strings in others,
        // so both are accepted, but halves are rejected: the signature is computed over
        // the exact string form, and a float would have no faithful representation.
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        throw InvalidWebhookSignature::missingHeader('midtrans', $key);
    }
}
