<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Lumineoss\Paystart\Bridge\Standalone\Exception\PersistenceError;
use Lumineoss\Paystart\Core\Domain\Event\DomainEvent;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentInstructionIssued;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentRefunded;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentStatusChanged;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethodKind;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;

/**
 * Turns an outbox row back into a DomainEvent.
 *
 * The payload is written by `payload()` on the event and read by this class, which is
 * the mirror image of the write side. Both directions are keyed by the same
 * `eventName`, so an event whose name is unknown here fails loudly instead of being
 * delivered as an empty envelope.
 *
 * Adding an event type therefore requires editing two places: the event class and the
 * match below. That is deliberate; the alternative is reflection-based
 * serialisation, which breaks silently when a constructor changes.
 */
final readonly class OutboxEventHydrator
{
    /**
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): DomainEvent
    {
        $name = $this->stringColumn($row, 'event_name');

        $payload = $this->decode($this->stringColumn($row, 'payload'), $name);

        $eventId = $this->stringColumn($row, 'event_id');
        $occurredAt = $this->time($this->stringColumn($row, 'occurred_at'));
        $aggregateId = $this->stringColumn($row, 'aggregate_id');
        $merchantId = $this->nullableColumn($row, 'merchant_id');
        $correlationId = $this->nullableColumn($row, 'correlation_id');

        return match ($name) {
            'payment.status_changed' => new PaymentStatusChanged(
                $eventId,
                $occurredAt,
                new PaymentId($aggregateId),
                $merchantId,
                $correlationId,
                PaymentStatus::from($this->required($payload, 'from', $name)),
                PaymentStatus::from($this->required($payload, 'to', $name)),
                $this->money($payload, $name),
                $this->method($payload, $name),
            ),
            'payment.instruction_issued' => new PaymentInstructionIssued(
                $eventId,
                $occurredAt,
                new PaymentId($aggregateId),
                $merchantId,
                $correlationId,
                $this->required($payload, 'instructionType', $name),
                (bool) ($payload['isStatic'] ?? false),
                isset($payload['expiresAt']) && \is_string($payload['expiresAt'])
                    ? new DateTimeImmutable($payload['expiresAt'], new DateTimeZone('UTC'))
                    : null,
                $this->money($payload, $name),
                \is_string($payload['description'] ?? null) ? $payload['description'] : '',
            ),
            'payment.refunded' => new PaymentRefunded(
                $eventId,
                $occurredAt,
                new PaymentId($aggregateId),
                $merchantId,
                $correlationId,
                new RefundId($this->required($payload, 'refundId', $name)),
                $this->money($payload, $name),
                Money::ofMinor(
                    $this->optionalInt($payload, 'remainingRefundableMinorUnits'),
                    Currency::of($this->required($payload, 'currency', $name)),
                ),
                $this->required($payload, 'status', $name),
            ),
            default => throw PersistenceError::unknownEvent($name),
        };
    }

    public function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function money(array $payload, string $eventName): Money
    {
        return Money::ofMinor(
            $this->optionalInt($payload, 'amountMinorUnits'),
            Currency::of($this->required($payload, 'currency', $eventName)),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function method(array $payload, string $eventName): PaymentMethod
    {
        // PaymentMethod::describe() is "kind" or "kind:channel", which is the inverse of
        // what the event payload stores. Reconstructing it here keeps the wire format
        // readable without teaching every consumer the PHP enum.
        $described = $this->required($payload, 'method', $eventName);
        $parts = explode(':', $described, 2);

        return new PaymentMethod(
            PaymentMethodKind::from($parts[0]),
            $parts[1] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function required(array $payload, string $key, string $eventName): string
    {
        $value = $payload[$key] ?? null;

        if (!\is_string($value)) {
            throw PersistenceError::malformedEventPayload($eventName, $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        throw PersistenceError::malformedEventPayload($key, get_debug_type($value));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json, string $eventName): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw PersistenceError::malformedEventPayload($eventName, $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw PersistenceError::malformedEventPayload($eventName, 'payload is not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
}
