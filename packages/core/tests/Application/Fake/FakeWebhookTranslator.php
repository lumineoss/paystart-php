<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Webhook\DeduplicationKey;
use Lumineoss\Paystart\Core\Port\Webhook\VerifiedWebhook;
use Lumineoss\Paystart\Core\Port\Webhook\WebhookTranslatorPort;

/**
 * Translator double with per-test overrides.
 *
 * The real mapping lives in the adapters; this exists to drive the handler's
 * decisions independently of provider vocabulary.
 */
class FakeWebhookTranslator implements WebhookTranslatorPort
{
    public ?PaymentStatus $status = null;

    public ?Money $amount = null;

    public string $category = 'payment.status';

    public function __construct(
        private string $provider = 'fake',
    ) {
    }

    public function eventCategory(VerifiedWebhook $webhook): string
    {
        return $this->category;
    }

    public function impliedStatus(VerifiedWebhook $webhook): ?PaymentStatus
    {
        return $this->status;
    }

    public function deduplicationKey(VerifiedWebhook $webhook): DeduplicationKey
    {
        return new DeduplicationKey($this->provider, $webhook->externalEventId);
    }

    public function impliedAmount(VerifiedWebhook $webhook): ?Money
    {
        return $this->amount;
    }
}
