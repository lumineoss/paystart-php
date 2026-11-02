<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use Closure;
use Lumineoss\Paystart\Core\Port\Persistence\TransactionManagerPort;
use RuntimeException;

/**
 * Records calls and throws on rollback, so tests can assert atomicity rather than
 * assume it.
 */
final class FakeTransactionManager implements TransactionManagerPort
{
    public int $begun = 0;

    public int $committed = 0;

    public int $rolledBack = 0;

    private int $depth = 0;

    public function transactional(Closure $operation): mixed
    {
        $this->begun++;
        $this->depth++;

        try {
            $result = $operation();
            $this->committed++;
            $this->depth--;

            return $result;
        } catch (\Throwable $e) {
            $this->rolledBack++;
            $this->depth--;

            throw $e;
        }
    }

    public function isInTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function assertBalanced(): void
    {
        if ($this->begun !== $this->committed + $this->rolledBack) {
            throw new RuntimeException('Unbalanced transaction bookkeeping in the fake.');
        }
    }
}
