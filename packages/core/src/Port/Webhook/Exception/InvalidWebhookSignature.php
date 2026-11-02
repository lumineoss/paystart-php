<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Webhook\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * The request did not prove it came from the provider.
 *
 * Distinct from a malformed payload: this means the signature or token did not
 * match, which is either a forgery or a misconfigured secret. Both deserve a
 * non-2xx response and an alert, never a silent accept.
 */
final class InvalidWebhookSignature extends RuntimeException implements PaystartException
{
    public static function mismatch(string $provider, string $reason): self
    {
        return new self(\sprintf('Webhook signature verification failed for "%s": %s.', $provider, $reason));
    }

    public static function missingHeader(string $provider, string $header): self
    {
        return new self(\sprintf('Webhook for "%s" is missing the required header "%s".', $provider, $header));
    }
}
