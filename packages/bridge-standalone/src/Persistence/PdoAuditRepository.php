<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use DateTimeImmutable;
use Lumineoss\Paystart\Core\Domain\Audit\AuditEntry;
use Lumineoss\Paystart\Core\Port\Persistence\AuditRepository;
use PDO;

/**
 * PDO append-only audit log.
 *
 * There is one method, and that is the entire design. No update, no delete, no clear.
 * A writable audit log proves nothing after an incident, so the capability is absent
 * rather than merely discouraged.
 *
 * Redaction is not performed here: AuditEntry has already rejected any non-scalar
 * context at construction. What arrives is written.
 */
final readonly class PdoAuditRepository implements AuditRepository
{
    public function __construct(
        private PDO $pdo,
    ) {
    }

    public function append(AuditEntry $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO paystart_audit_entries
                (action, occurred_at, merchant_id, payment_id, actor_type, actor_id,
                 context, correlation_id, corrects_entry_id)
             VALUES
                (:action, :occurred_at, :merchant_id, :payment_id, :actor_type, :actor_id,
                 :context, :correlation_id, :corrects_entry_id)',
        );

        $statement->execute([
            'action' => $entry->action->value,
            'occurred_at' => $this->format($entry->occurredAt),
            'merchant_id' => $entry->merchantId,
            'payment_id' => $entry->paymentId,
            'actor_type' => $entry->actorType,
            'actor_id' => $entry->actorId,
            // JSON_THROW_ON_ERROR rather than an object cast: a serialisation failure here
            // must not silently store "Array" or an empty object in an audit record.
            'context' => json_encode($entry->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'correlation_id' => $entry->correlationId,
            'corrects_entry_id' => $entry->correctsEntryId,
        ]);
    }

    private function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
