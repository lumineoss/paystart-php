<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Webhook;

use Lumineoss\Paystart\Core\Domain\Shared\RawRequest;

/**
 * Proves an incoming webhook really came from the provider.
 *
 * Why this is its own port and not part of the gateway port: the three providers
 * verify completely differently. Stripe uses an HMAC-SHA256 signature header with
 * a timestamp tolerance, Xendit uses a shared bearer token in `x-callback-token`
 * with no signature at all, and Midtrans uses
 * `SHA512(order_id + status_code + gross_amount + ServerKey)` computed over fields
 * inside the body. Hiding that behind one interface keeps the differences in the
 * adapters where they can be tested against known-answer vectors.
 */
interface WebhookVerifierPort
{
    /**
     * @throws \Lumineoss\Paystart\Core\Port\Webhook\Exception\InvalidWebhookSignature
     * @throws \Lumineoss\Paystart\Core\Port\Webhook\Exception\WebhookVerificationUnavailable
     */
    public function verify(RawRequest $request): VerifiedWebhook;
}
