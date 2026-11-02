<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Event;

use DateTimeImmutable;

/**
 * Something that already happened in this library, published to the host.
 *
 * Events are written to an outbox inside the same transaction as the state change,
 * then published after commit. Publishing inline would mean a consumer sees a
 * payment that a later rollback erases, which is unrecoverable for the consumer.
 *
 * `eventId` is stable across retries, so a subscriber can deduplicate.
 */
interface DomainEvent
{
    public function eventId(): string;

    public function occurredAt(): DateTimeImmutable;

    /**
     * Name used on the wire and in the outbox. Stable across releases.
     */
    public function eventName(): string;

    /**
     * The aggregate this event is about.
     */
    public function aggregateId(): string;

    public function merchantId(): ?string;

    public function correlationId(): ?string;

    /**
     * Serialisable body. Must never contain PAN, tokens, QR content, or URLs: those
     * are bearer-like credentials and an event stream is readable by many systems.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
