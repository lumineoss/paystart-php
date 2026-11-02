<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * Stored data could not be turned back into a domain object.
 *
 * Always a bug, never a user error: it means either the schema and the encoder
 * disagree, or a row was written by a different version of this library. Surfacing it
 * as a plain exception rather than defaulting keeps a bad payment from being served as
 * if it were fine.
 */
final class PersistenceError extends RuntimeException implements PaystartException
{
    public static function malformedPayload(string $type, string $reason): self
    {
        return new self(\sprintf('Cannot decode a "%s" instruction payload: %s.', $type, $reason));
    }

    public static function unsupportedPayload(string $class): self
    {
        return new self(\sprintf(
            'No encoder for payload %s. Add one before persisting this instruction type.',
            $class,
        ));
    }

    public static function malformedRow(string $column, string $actualType): self
    {
        return new self(\sprintf(
            'Column "%s" holds a %s, which is not the type this repository reads. '
            . 'The row was probably written by a different version or by hand.',
            $column,
            $actualType,
        ));
    }

    public static function missingColumn(string $column): self
    {
        return new self(\sprintf('Column "%s" is missing from the selected row.', $column));
    }

    public static function unknownEvent(string $name): self
    {
        return new self(\sprintf(
            'Unknown outbox event "%s". Add a case to OutboxEventHydrator or the event '
            . 'cannot be replayed, which loses the notification permanently.',
            $name,
        ));
    }

    public static function malformedEventPayload(string $eventName, string $reason): self
    {
        return new self(\sprintf('Cannot decode the payload of "%s": %s.', $eventName, $reason));
    }

    public static function migrationFailed(string $file, string $reason): self
    {
        return new self(\sprintf('Migration "%s" failed: %s.', $file, $reason));
    }
}
