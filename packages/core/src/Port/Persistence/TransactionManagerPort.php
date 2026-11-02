<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Persistence;

use Closure;

/**
 * Transaction boundary.
 *
 * The unit of work belongs to the application handler, never to a repository: a
 * handler that saves a payment and appends an outbox row must have both committed
 * or neither. Repositories that opened their own transactions would make that
 * impossible.
 *
 * Implementations must support nesting by treating an inner call as a no-op that
 * joins the outer transaction, or by savepoints. They must not silently create an
 * independent transaction, which would defeat the atomicity the outbox depends on.
 */
interface TransactionManagerPort
{
    /**
     * Runs the closure inside one transaction and returns its result.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function transactional(Closure $operation): mixed;

    /**
     * Whether a transaction is currently open on this manager. Handlers use it to
     * refuse running a multi-write operation outside a transaction.
     */
    public function isInTransaction(): bool;
}
