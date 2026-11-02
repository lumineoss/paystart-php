<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * Base for typed identifiers.
 *
 * Each subclass fixes its own prefix, so passing a RefundId where a PaymentId is
 * expected is a static analysis error rather than a production incident. The
 * prefix also makes a stray identifier recognisable in a log.
 *
 * @phpstan-consistent-constructor
 */
abstract readonly class PrefixedId
{
    public string $value;

    public function __construct(string $value)
    {
        $prefix = static::prefix();

        if ($prefix === '') {
            throw InvalidArgument::because(\sprintf('%s does not declare an identifier prefix.', static::class));
        }

        $pattern = \sprintf('/\A%s_[0-9a-z]{8,64}\z/', preg_quote($prefix, '/'));

        if (preg_match($pattern, $value) !== 1) {
            throw InvalidArgument::because(\sprintf(
                'Identifier "%s" is not a valid %s; expected a "%s_" prefix followed by 8 to 64 lowercase alphanumerics.',
                $value,
                static::class,
                $prefix,
            ));
        }

        $this->value = $value;
    }

    public static function fromString(string $value): static
    {
        return new static($value);
    }

    /**
     * @return non-empty-string
     */
    abstract public static function prefix(): string;

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
