<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Persistence;

use Lumineoss\Paystart\Core\Domain\Event\DomainEvent;

/**
 * The transactional outbox.
 *
 * Written inside the same transaction as the state change, published afterwards.
 * This is what makes "the event was published" and "the state changed" agree: a
 * rollback discards both, and a crash after commit leaves the event recoverable from
 * the table rather than lost.
 *
 * Note that this port only appends and drains. Delivery to a broker is a host
 * concern; the library guarantees the record exists exactly once and that draining
 * is ordered per aggregate.
 */
interface OutboxRepository
{
    public function append(DomainEvent $event): void;

    /**
     * Oldest unpublished events, in append order, limited for batch safety.
     *
     * @return list<DomainEvent>
     */
    public function pending(int $limit = 100): array;

    /**
     * Marks an event as published. Must be idempotent: a crash between publishing
     * and marking results in a repeat delivery, which subscribers deduplicate by
     * `eventId`.
     */
    public function markPublished(string $eventId, \DateTimeImmutable $publishedAt): void;

    public function pendingCount(): int;
}
