<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Webhook;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Webhook\DeduplicationKey;

/**
 * Translates a verified provider webhook into our vocabulary.
 *
 * Separate from `WebhookVerifierPort` on purpose. Verification is a security concern
 * with completely different mechanisms per provider; interpretation is a mapping
 * concern. Keeping them apart means a mapping bug cannot weaken verification, and
 * each can be tested with known-answer vectors independently.
 */
interface WebhookTranslatorPort
{
    /**
     * The event category, used for inbox bookkeeping and for choosing a handler.
     *
     * Examples: "payment.status", "refund.status", "subscription.status".
     */
    public function eventCategory(VerifiedWebhook $webhook): string;

    /**
     * The status this webhook implies, if it implies one.
     *
     * Returns null when the event is not a status change (a refund notification, for
     * instance), so the caller does not have to guess.
     */
    public function impliedStatus(VerifiedWebhook $webhook): ?PaymentStatus;

    /**
     * A stable identity for deduplication.
     *
     * Stripe supplies a unique event id; Midtrans and Xendit often supply only
     * `order_id` plus `transaction_status`, which means a genuine re-notification of
     * the same state is indistinguishable from a duplicate. That is acceptable
     * because re-applying a same-state transition is a no-op in the aggregate.
     */
    public function deduplicationKey(VerifiedWebhook $webhook): DeduplicationKey;

    /**
     * The amount the provider reports in this webhook, if it reports one.
     *
     * Read from the provider's own field, not derived. A mismatch against the local
     * amount is a reconciliation incident, so the raw value has to survive: this is
     * also the value that signature verification hashed.
     */
    public function impliedAmount(VerifiedWebhook $webhook): ?Money;
}
