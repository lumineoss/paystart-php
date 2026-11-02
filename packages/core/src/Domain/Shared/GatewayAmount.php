<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * An amount expressed in the unit a gateway expects.
 *
 * This is a distinct type from Money on purpose (ADR 0001). A gateway may accept
 * major units (Midtrans and Xendit want whole rupiah for IDR) while the ledger
 * must keep ISO minor units. Keeping the two apart means the conversion is
 * always a visible call, never an implicit assumption.
 */
final readonly class GatewayAmount
{
    public function __construct(
        public int|string $value,
    ) {
        if (\is_string($value)) {
            if (preg_match('/\A-?\d+\z/', $value) !== 1) {
                throw InvalidArgument::because(\sprintf(
                    'Gateway amount string must be an integer string, got "%s".',
                    $value,
                ));
            }

            return;
        }

        if ($value < 0) {
            throw InvalidArgument::because(\sprintf('Gateway amount must not be negative, got %d.', $value));
        }
    }

    public static function ofMinor(int $minorUnits): self
    {
        return new self($minorUnits);
    }

    /**
     * Kept as a string because that is what providers send in notifications and
     * what signature verification needs verbatim.
     */
    public static function ofString(string $value): self
    {
        return new self($value);
    }

    public function toInt(): int
    {
        return (int) $this->value;
    }

    public function toString(): string
    {
        return (string) $this->value;
    }
}
