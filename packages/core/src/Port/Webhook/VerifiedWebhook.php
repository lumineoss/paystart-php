<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Webhook;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;

/**
 * A webhook whose origin has been cryptographically confirmed.
 *
 * Constructed only by a verifier, so a handler receiving one can treat the source
 * as proven. It still says nothing about whether the event is new or in what order
 * it arrived: deduplication and ordering are the inbox's job, and the deduplication
 * key itself comes from WebhookTranslatorPort because it is provider-specific
 * (Stripe ships a unique event id; Midtrans and Xendit often do not).
 */
final readonly class VerifiedWebhook
{
    /**
     * @param array<string, mixed> $payload decoded body, safe to read because the source is proven
     */
    public function __construct(
        public string $provider,
        public string $externalEventId,
        public string $eventType,
        public GatewayReference $reference,
        public array $payload,
        public DateTimeImmutable $receivedAt,
        public ?string $rawBody = null,
    ) {
    }
}
