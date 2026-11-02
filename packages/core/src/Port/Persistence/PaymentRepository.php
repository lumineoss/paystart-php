<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Persistence;

use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;

/**
 * Persistence contract for the Payment aggregate.
 *
 * A repository loads and stores whole aggregates. There is no partial update and
 * no lazy loading: a caller that receives a Payment can count on it being complete.
 *
 * `Refund` is an entity inside `Payment`, so it has no repository of its own.
 */
interface PaymentRepository
{
    public function nextId(): PaymentId;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound
     */
    public function get(PaymentId $id): Payment;

    public function find(PaymentId $id): ?Payment;

    /**
     * Looks up a payment by a provider reference, for webhook routing.
     *
     * A reference belongs to one attempt, so this resolves to the payment that owns
     * it. Returns null when no local payment matches, which a handler must treat as
     * "not ours", not as an error.
     */
    public function findByGatewayReference(GatewayReference $reference): ?Payment;

    /**
     * Inserts or updates, using the aggregate's version to detect concurrent
     * modification.
     *
     * @throws \Lumineoss\Paystart\Core\Domain\Shared\Exception\ConcurrencyConflict
     */
    public function save(Payment $payment): void;
}
