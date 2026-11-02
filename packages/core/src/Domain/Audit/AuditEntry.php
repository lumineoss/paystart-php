<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Audit;

use DateTimeImmutable;

/**
 * One immutable record of something that happened to money.
 *
 * Append-only by design: there is no update or delete operation anywhere in the
 * library, and no repository method that could express one. A correction is a new
 * entry that references the one it corrects, which is what makes the log trustworthy
 * after an incident.
 *
 * Redaction happens before the entry is created, not at read time. Anything written
 * here is written permanently.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, scalar|null> $context non-sensitive fields only
     */
    public function __construct(
        public AuditAction $action,
        public DateTimeImmutable $occurredAt,
        public ?string $merchantId,
        public ?string $paymentId,
        public ?string $actorType,
        public ?string $actorId,
        public array $context = [],
        public ?string $correlationId = null,
        public ?string $correctsEntryId = null,
    ) {
        foreach ($context as $key => $value) {
            if (!\is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException(\sprintf(
                    'Audit context "%s" must be scalar or null; nested structures leak sensitive data.',
                    $key,
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'occurredAt' => $this->occurredAt->format(DATE_ATOM),
            'merchantId' => $this->merchantId,
            'paymentId' => $this->paymentId,
            'actorType' => $this->actorType,
            'actorId' => $this->actorId,
            'context' => $this->context,
            'correlationId' => $this->correlationId,
            'correctsEntryId' => $this->correctsEntryId,
        ];
    }
}
