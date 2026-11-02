<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Event;

use DateTimeImmutable;

/**
 * Convenience base for the common case.
 *
 * Subclasses fix the name and the payload shape; identity and timing are handled
 * here so every event is consistent and deduplicable.
 */
abstract readonly class AbstractDomainEvent implements DomainEvent
{
    public function __construct(
        private string $id,
        private DateTimeImmutable $occurredAt,
        private string $aggregateId,
        private ?string $merchantId = null,
        private ?string $correlation = null,
    ) {
    }

    public function eventId(): string
    {
        return $this->id;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    public function merchantId(): ?string
    {
        return $this->merchantId;
    }

    public function correlationId(): ?string
    {
        return $this->correlation;
    }

    /**
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        return [
            'eventId' => $this->eventId(),
            'eventName' => $this->eventName(),
            'aggregateId' => $this->aggregateId(),
            'merchantId' => $this->merchantId(),
            'correlationId' => $this->correlationId(),
            'occurredAt' => $this->occurredAt()->format(DATE_ATOM),
            'payload' => $this->payload(),
        ];
    }
}
