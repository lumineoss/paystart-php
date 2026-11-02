<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Persistence;

use Lumineoss\Paystart\Core\Domain\Audit\AuditEntry;

/**
 * Append-only audit sink.
 *
 * Note what is absent: there is no update, no delete, and no "clear". That is the
 * point. A writable audit log proves nothing after an incident.
 */
interface AuditRepository
{
    public function append(AuditEntry $entry): void;
}
