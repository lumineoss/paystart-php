<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEvent;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventId;
use Lumineoss\Paystart\Core\Port\Persistence\WebhookInbox;

/**
 * Enforces the same uniqueness guarantee the real inbox must provide: a claim on a
 * (provider, deduplicationKey) pair succeeds exactly once.
 */
final class InMemoryWebhookInbox implements WebhookInbox
{
    /** @var array<string, WebhookEvent> */
    private array $events = [];

    /** @var array<string, string> dedup key => event id */
    private array $claimed = [];

    private int $sequence = 0;

    public function nextId(): WebhookEventId
    {
        $this->sequence++;

        return new WebhookEventId(\sprintf('whk_%026d', $this->sequence));
    }

    public function claim(WebhookEvent $event): ?WebhookEvent
    {
        if (isset($this->claimed[$event->deduplicationKey])) {
            return null;
        }

        $this->claimed[$event->deduplicationKey] = $event->id->value;
        $this->events[$event->id->value] = $event;

        return $event;
    }

    public function get(WebhookEventId $id): WebhookEvent
    {
        return $this->events[$id->value] ?? throw NotFound::aggregate('WebhookEvent', $id->value);
    }

    public function save(WebhookEvent $event): void
    {
        $this->events[$event->id->value] = $event;
    }

    public function hasProcessed(string $provider, string $deduplicationKey): bool
    {
        return isset($this->claimed[$deduplicationKey]);
    }

    /**
     * @return list<WebhookEvent>
     */
    public function all(): array
    {
        return array_values($this->events);
    }
}
