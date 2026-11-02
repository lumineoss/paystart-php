<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Event;

use Lumineoss\Paystart\Core\Domain\Event\DomainEvent;

/**
 * Publishes already-committed events to the host.
 *
 * Kept separate from `OutboxRepository`: the outbox is storage, this is transport.
 * A Laravel host wires this to its event dispatcher, a Symfony host to its own, and a
 * standalone app can publish to a queue or do nothing at all.
 *
 * Implementations must tolerate being called twice for the same event.
 */
interface EventPublisher
{
    public function publish(DomainEvent $event): void;
}
