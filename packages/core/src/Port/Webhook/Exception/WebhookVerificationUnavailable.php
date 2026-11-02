<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Webhook\Exception;

use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use RuntimeException;

/**
 * Verification could not be attempted at all because a secret is missing.
 *
 * This must fail closed. Accepting an unverified webhook because no secret was
 * configured is exactly the failure mode that leads to a forged "settlement".
 */
final class WebhookVerificationUnavailable extends RuntimeException implements PaystartException
{
    public static function missingSecret(string $provider): self
    {
        return new self(\sprintf(
            'No webhook secret is configured for "%s". Refusing to accept an unverified webhook.',
            $provider,
        ));
    }
}
