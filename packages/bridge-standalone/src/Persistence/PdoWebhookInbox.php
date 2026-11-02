<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Lumineoss\Paystart\Bridge\Standalone\Exception\PersistenceError;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound;
use Lumineoss\Paystart\Core\Domain\Shared\IdGenerator;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEvent;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventId;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventStatus;
use Lumineoss\Paystart\Core\Port\Persistence\WebhookInbox;
use PDO;
use PDOException;

/**
 * PDO implementation of the webhook inbox.
 *
 * `claim` is the reason this class exists. It relies on the unique index over
 * `deduplication_key`: the insert either wins or raises a duplicate-key violation. A
 * `SELECT` followed by an `INSERT` would look correct in a single-threaded test and
 * still lose the race in production, so no read is performed here.
 *
 * The duplicate case is detected from SQLSTATE 23000 specifically, not by catching any
 * PDOException. A connection failure must never be reported as "already processed",
 * because that tells the provider to stop retrying an event we never stored.
 */
final readonly class PdoWebhookInbox implements WebhookInbox
{
    public function __construct(
        private PDO $pdo,
        private IdGenerator $ids,
    ) {
    }

    public function nextId(): WebhookEventId
    {
        return new WebhookEventId($this->ids->generate('whk'));
    }

    public function claim(WebhookEvent $event): ?WebhookEvent
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO paystart_webhook_events
                (id, provider, external_event_id, deduplication_key, event_type,
                 gateway_reference, payload, status, failure_reason, received_at)
             VALUES
                (:id, :provider, :external_event_id, :deduplication_key, :event_type,
                 :gateway_reference, :payload, :status, NULL, :received_at)',
        );

        try {
            $statement->execute([
                'id' => $event->id->value,
                'provider' => $event->provider,
                'external_event_id' => $event->externalEventId,
                'deduplication_key' => $event->deduplicationKey,
                'event_type' => $event->eventType,
                'gateway_reference' => $event->gatewayReference,
                'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'status' => $event->status()->value,
                'received_at' => $this->format($event->receivedAt),
            ]);
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                // Another delivery already claimed this event. Not an error: the caller
                // acknowledges without applying anything.
                return null;
            }

            throw $e;
        }

        return $event;
    }

    public function get(WebhookEventId $id): WebhookEvent
    {
        $statement = $this->pdo->prepare('SELECT * FROM paystart_webhook_events WHERE id = :id');
        $statement->execute(['id' => $id->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            throw NotFound::aggregate('WebhookEvent', $id->value);
        }

        return $this->hydrate($row);
    }

    public function save(WebhookEvent $event): void
    {
        // Only the mutable fields are written. The payload, deduplication key and
        // external id are what was received, and rewriting them would make a stored
        // event describe something that never arrived.
        $statement = $this->pdo->prepare(
            'UPDATE paystart_webhook_events
                SET status = :status, failure_reason = :failure_reason
              WHERE id = :id',
        );

        $statement->execute([
            'status' => $event->status()->value,
            'failure_reason' => $event->failureReason(),
            'id' => $event->id->value,
        ]);
    }

    public function hasProcessed(string $provider, string $deduplicationKey): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM paystart_webhook_events
              WHERE provider = :provider AND deduplication_key = :key AND status = :status
              LIMIT 1',
        );

        $statement->execute([
            'provider' => $provider,
            'key' => $deduplicationKey,
            'status' => WebhookEventStatus::Processed->value,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WebhookEvent
    {
        $payload = $row['payload'] ?? null;

        if (!\is_string($payload)) {
            throw PersistenceError::malformedRow('payload', get_debug_type($payload));
        }

        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw PersistenceError::malformedPayload('webhook', $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw PersistenceError::malformedPayload('webhook', 'payload is not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return new WebhookEvent(
            new WebhookEventId($this->stringColumn($row, 'id')),
            $this->stringColumn($row, 'provider'),
            $this->stringColumn($row, 'external_event_id'),
            $this->stringColumn($row, 'deduplication_key'),
            $this->stringColumn($row, 'event_type'),
            $this->nullableColumn($row, 'gateway_reference'),
            $decoded,
            $this->time($this->stringColumn($row, 'received_at')),
            WebhookEventStatus::from($this->stringColumn($row, 'status')),
            $this->nullableColumn($row, 'failure_reason'),
        );
    }

    /**
     * SQLSTATE 23000 is an integrity constraint violation. For this statement the only
     * constraint that can fire is the deduplication unique key, because every other
     * column is non-null and the primary key is generated here.
     */
    private function isUniqueViolation(PDOException $e): bool
    {
        return $e->getCode() === '23000';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringColumn(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!\is_string($value)) {
            throw PersistenceError::malformedRow($key, get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableColumn(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw PersistenceError::malformedRow($key, get_debug_type($value));
        }

        return $value;
    }

    private function time(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        return $parsed === false ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : $parsed;
    }

    private function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
