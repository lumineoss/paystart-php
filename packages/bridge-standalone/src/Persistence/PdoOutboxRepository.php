<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Event\DomainEvent;
use Lumineoss\Paystart\Core\Port\Persistence\OutboxRepository;
use PDO;

/**
 * PDO transactional outbox.
 *
 * `append` performs no commit of its own. That is the contract: the caller runs the
 * state change and the append inside one `PdoTransactionManager` transaction, so a
 * rollback discards both and a crash after commit leaves the event in the table where a
 * publisher can find it.
 *
 * `pending` reads in `sequence` order, which is insertion order, because the outbox
 * column is auto-increment. Ordering per aggregate is therefore guaranteed as long as
 * two events for one aggregate are appended in the order the domain produced them,
 * which is what the handlers do.
 */
final readonly class PdoOutboxRepository implements OutboxRepository
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function append(DomainEvent $event): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO paystart_outbox
                (event_id, event_name, aggregate_id, merchant_id, correlation_id,
                 payload, occurred_at, published_at)
             VALUES
                (:event_id, :event_name, :aggregate_id, :merchant_id, :correlation_id,
                 :payload, :occurred_at, NULL)',
        );

        $statement->execute([
            'event_id' => $event->eventId(),
            'event_name' => $event->eventName(),
            'aggregate_id' => $event->aggregateId(),
            'merchant_id' => $event->merchantId(),
            'correlation_id' => $event->correlationId(),
            'payload' => json_encode($event->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function pending(int $limit = 100): array
    {
        // The limit is clamped rather than trusted: a publisher passing a huge value
        // would load the whole table into memory during a backlog.
        $safeLimit = max(1, min($limit, 1000));

        $statement = $this->pdo->prepare(
            'SELECT * FROM paystart_outbox
              WHERE published_at IS NULL
              ORDER BY sequence ASC
              LIMIT :limit',
        );

        $statement->bindValue('limit', $safeLimit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $hydrator = new OutboxEventHydrator();
        $events = [];

        foreach ($rows as $row) {
            $events[] = $hydrator->hydrate($row);
        }

        return $events;
    }

    public function markPublished(string $eventId, DateTimeImmutable $publishedAt): void
    {
        // Only an unpublished row is updated, so a second publisher that raced gets a
        // no-op instead of rewriting the original publication time.
        $statement = $this->pdo->prepare(
            'UPDATE paystart_outbox
                SET published_at = :published_at
              WHERE event_id = :event_id AND published_at IS NULL',
        );

        $statement->execute([
            'published_at' => $publishedAt->format('Y-m-d H:i:s'),
            'event_id' => $eventId,
        ]);
    }

    public function pendingCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM paystart_outbox WHERE published_at IS NULL');

        if ($statement === false) {
            throw new \RuntimeException('Could not count the outbox backlog.');
        }

        return (int) $statement->fetchColumn();
    }
}
