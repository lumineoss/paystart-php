<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

/**
 * Typed payload of a PaymentInstruction.
 *
 * Sealed by convention: one implementation per instruction type, each carrying
 * only the fields that type needs. Consumers pattern-match on the concrete class
 * rather than reaching into an associative array of provider keys.
 */
interface InstructionPayload
{
    /**
     * A short, non-sensitive description for audit logs.
     *
     * Must never include the URL, QR content, or account number: those are
     * bearer-like credentials. Adapters implement this to name the shape only.
     */
    public function describeForAudit(): string;
}
