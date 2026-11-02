<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use Closure;
use Lumineoss\Paystart\Core\Port\Persistence\TransactionManagerPort;
use PDO;
use Throwable;

/**
 * PDO transaction boundary.
 *
 * Nesting joins the outer transaction instead of opening a second one. A second,
 * independent transaction would let a handler's outbox write commit separately from
 * the state change, which defeats the point of the outbox entirely.
 *
 * Savepoints are not used: nothing in this library catches an inner failure and
 * continues, so a nested rollback would only ever mean "the whole thing fails".
 */
final class PdoTransactionManager implements TransactionManagerPort
{
    private int $depth = 0;

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function transactional(Closure $operation): mixed
    {
        if ($this->depth > 0) {
            // Already inside a transaction: join it. No BEGIN, and no COMMIT on the way
            // out, because the outer caller owns the boundary.
            $this->depth++;

            try {
                return $operation();
            } finally {
                $this->depth--;
            }
        }

        $this->pdo->beginTransaction();
        $this->depth++;

        try {
            $result = $operation();
            $this->depth--;
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->depth--;

            // Only roll back if the driver still thinks a transaction is open. A failed
            // commit can leave PDO without one, and calling rollBack() then throws and
            // masks the original error, which is the one that matters.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function isInTransaction(): bool
    {
        return $this->depth > 0;
    }
}
