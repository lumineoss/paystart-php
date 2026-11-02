<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Persistence;

use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEvent;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventId;

/**
 * The webhook inbox.
 *
 * `claim` is the whole design: it must be atomic, so that two workers receiving the
 * same redelivered event cannot both proceed. A caller that gets `null` from
 * `claim` is looking at a duplicate and must acknowledge without side effects.
 *
 * The natural implementation is a unique index on (provider, deduplication_key)
 * plus an insert that reports whether it won. That is a database guarantee, not a
 * read-then-write check, which would race.
 */
interface WebhookInbox
{
    public function nextId(): WebhookEventId;

    /**
     * Attempts to record the event as the first handler of it.
     *
     * @return WebhookEvent|null the recorded event when this call claimed it, or
     *                            null when another delivery already did
     */
    public function claim(WebhookEvent $event): ?WebhookEvent;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound
     */
    public function get(WebhookEventId $id): WebhookEvent;

    public function save(WebhookEvent $event): void;

    public function hasProcessed(string $provider, string $deduplicationKey): bool;
}
