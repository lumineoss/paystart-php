<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use Lumineoss\Paystart\Core\Domain\Audit\AuditEntry;
use Lumineoss\Paystart\Core\Port\Persistence\AuditRepository;

final class InMemoryAuditRepository implements AuditRepository
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function append(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<AuditEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn (AuditEntry $e): string => $e->action->value, $this->entries);
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
