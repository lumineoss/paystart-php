<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Webhook;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * A webhook we have accepted and recorded.
 *
 * The inbox exists because delivery is at-least-once and unordered. Both Midtrans
 * and Xendit document that they may resend, and Midtrans explicitly warns that
 * `settlement` can arrive before `pending`. Recording every event before acting on
 * it is what makes processing idempotent and replayable.
 *
 * Status is mutable within this record only through the mark* methods; the payload
 * and identity are immutable once written.
 */
final class WebhookEvent
{
    private WebhookEventStatus $status;

    private ?string $failureReason;

    /**
     * @param array<string, mixed> $payload decoded body; already signature-verified
     */
    public function __construct(
        public readonly WebhookEventId $id,
        public readonly string $provider,
        public readonly string $externalEventId,
        public readonly string $deduplicationKey,
        public readonly string $eventType,
        public readonly ?string $gatewayReference,
        public readonly array $payload,
        public readonly DateTimeImmutable $receivedAt,
        WebhookEventStatus $status = WebhookEventStatus::Received,
        ?string $failureReason = null,
    ) {
        if (trim($provider) === '') {
            throw InvalidArgument::because('Webhook provider must not be empty.');
        }

        if (trim($deduplicationKey) === '') {
            throw InvalidArgument::because('Webhook deduplication key must not be empty.');
        }

        $this->status = $status;
        $this->failureReason = $failureReason;
    }

    public function status(): WebhookEventStatus
    {
        return $this->status;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function markProcessed(DateTimeImmutable $now): void
    {
        $this->status = WebhookEventStatus::Processed;
    }

    public function markIgnored(string $reason): void
    {
        // A duplicate is not a failure. Recording why it was ignored is what lets
        // an investigator distinguish "already handled" from "we dropped it".
        $this->status = WebhookEventStatus::Ignored;
        $this->failureReason = $reason;
    }

    public function markFailed(string $reason): void
    {
        $this->status = WebhookEventStatus::Failed;
        $this->failureReason = $reason;
    }
}
