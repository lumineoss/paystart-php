<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * Caller-supplied key that makes a write retry-safe.
 *
 * The library requires one on every operation that can move money, because
 * retries are normal and double-charging is not. Keys are opaque: their content
 * is the caller's business, only presence, form, and bounded length are enforced.
 */
final readonly class IdempotencyKey
{
    public const MAX_LENGTH = 255;

    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidArgument::because('Idempotency key must not be empty.');
        }

        if (\strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidArgument::because(\sprintf(
                'Idempotency key must be at most %d bytes, got %d.',
                self::MAX_LENGTH,
                \strlen($trimmed),
            ));
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $trimmed) === 1) {
            throw InvalidArgument::because('Idempotency key must not contain control characters.');
        }

        $this->value = $trimmed;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
