<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Payload;

use Lumineoss\Paystart\Core\Domain\Payment\InstructionPayload;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * A 3DS challenge the payer must complete.
 *
 * The token is bearer-like: it authenticates a specific authentication session.
 */
final readonly class AuthChallengePayload implements InstructionPayload
{
    public function __construct(
        public string $redirectUrl,
        public string $challengeToken,
    ) {
        if (filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) {
            throw InvalidArgument::because('3DS challenge redirect URL is not a valid URL.');
        }

        if (trim($challengeToken) === '') {
            throw InvalidArgument::because('3DS challenge token must not be empty.');
        }
    }

    public function describeForAudit(): string
    {
        return 'auth_challenge_3ds';
    }
}
