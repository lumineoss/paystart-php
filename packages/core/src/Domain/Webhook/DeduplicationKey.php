<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Webhook;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * The identity a webhook is deduplicated on.
 *
 * A dedicated value object rather than a raw string, because the key is the thing that
 * decides whether a payment is processed twice. Building it in one place means every
 * adapter composes it the same way and an empty or unbounded key is impossible.
 *
 * The provider is part of the key: two providers can legitimately use the same
 * external id, and collision there would mean silently dropping a real event.
 */
final readonly class DeduplicationKey
{
    public const MAX_LENGTH = 191;

    public string $value;

    public function __construct(
        public string $provider,
        public string $externalId,
    ) {
        if (trim($provider) === '') {
            throw InvalidArgument::because('Deduplication key requires a provider.');
        }

        if (trim($externalId) === '') {
            throw InvalidArgument::because('Deduplication key requires an external event id.');
        }

        $composed = $provider . ':' . $externalId;

        if (\strlen($composed) > self::MAX_LENGTH) {
            // Hashed rather than truncated: a truncated key can collide, and a
            // collision here means a real payment notification is discarded.
            $composed = $provider . ':sha256:' . hash('sha256', $externalId);
        }

        $this->value = $composed;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
