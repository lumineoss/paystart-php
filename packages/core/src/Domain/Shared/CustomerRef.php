<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

/**
 * A payer identity or reference supplied by the host.
 *
 * Opaque on purpose: the library never stores or copies sensitive payer data,
 * it only carries a reference the host can resolve.
 */
final readonly class CustomerRef
{
    public function __construct(
        public string $value,
    ) {
        if (trim($value) === '') {
            throw Exception\InvalidArgument::because('Customer reference must not be empty.');
        }

        if (\strlen($value) > 255) {
            throw Exception\InvalidArgument::because('Customer reference must be at most 255 bytes.');
        }
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
