<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Event\DomainEvent;
use Lumineoss\Paystart\Core\Port\Persistence\OutboxRepository;

final class InMemoryOutbox implements OutboxRepository
{
    /** @var list<DomainEvent> */
    private array $events = [];

    /** @var array<string, DateTimeImmutable> */
    private array $published = [];

    public function append(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    public function pending(int $limit = 100): array
    {
        $pending = [];

        foreach ($this->events as $event) {
            if (!isset($this->published[$event->eventId()])) {
                $pending[] = $event;
            }

            if (\count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    public function markPublished(string $eventId, DateTimeImmutable $publishedAt): void
    {
        $this->published[$eventId] = $publishedAt;
    }

    public function pendingCount(): int
    {
        return \count($this->pending(PHP_INT_MAX));
    }

    /**
     * @return list<DomainEvent>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn (DomainEvent $e): string => $e->eventName(), $this->events);
    }
}
