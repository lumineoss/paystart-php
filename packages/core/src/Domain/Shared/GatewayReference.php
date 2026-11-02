<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * A provider-issued identifier.
 *
 * Never used as our primary key: a gateway reference is scoped to one provider,
 * and a payment can have several over its life. Kept only to talk back to the
 * provider.
 */
final readonly class GatewayReference
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidArgument::because('Gateway reference must not be empty.');
        }

        if (\strlen($trimmed) > 255) {
            throw InvalidArgument::because(\sprintf(
                'Gateway reference must be at most 255 bytes, got %d.',
                \strlen($trimmed),
            ));
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $trimmed) === 1) {
            throw InvalidArgument::because('Gateway reference must not contain control characters.');
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
