<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use Lumineoss\Paystart\Core\Domain\Shared\RawRequest;
use Lumineoss\Paystart\Core\Port\Webhook\VerifiedWebhook;
use Lumineoss\Paystart\Core\Port\Webhook\WebhookVerifierPort;

/**
 * Verifier double.
 *
 * Records whether it was called so a test can prove verification happens before
 * anything else touches the body.
 */
class FakeWebhookVerifier implements WebhookVerifierPort
{
    public int $calls = 0;

    public ?string $lastBody = null;

    public ?VerifiedWebhook $result = null;

    public ?\Throwable $throw = null;

    public function verify(RawRequest $request): VerifiedWebhook
    {
        $this->calls++;
        $this->lastBody = $request->body;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result ?? throw new \LogicException('FakeWebhookVerifier has no result configured.');
    }
}
