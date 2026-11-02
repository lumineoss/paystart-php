<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Audit;

/**
 * What happened, in terms stable enough to build reports and alerts on.
 *
 * Adding a case is safe. Renaming one is a breaking change, because stored audit
 * rows and dashboards reference the value.
 */
enum AuditAction: string
{
    case PaymentCreated = 'payment.created';
    case PaymentInstructionIssued = 'payment.instruction_issued';
    case PaymentInstructionRefreshed = 'payment.instruction_refreshed';
    case PaymentAuthorized = 'payment.authorized';
    case PaymentCaptured = 'payment.captured';
    case PaymentSettled = 'payment.settled';
    case PaymentFailed = 'payment.failed';
    case PaymentExpired = 'payment.expired';
    case PaymentCanceled = 'payment.canceled';
    case PaymentVoided = 'payment.voided';
    case RefundRequested = 'refund.requested';
    case RefundSucceeded = 'refund.succeeded';
    case RefundFailed = 'refund.failed';
    case WebhookReceived = 'webhook.received';
    case WebhookRejected = 'webhook.rejected';
    case WebhookDuplicateIgnored = 'webhook.duplicate_ignored';
    case ReconciliationMismatch = 'reconciliation.mismatch';
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionDisabled = 'subscription.disabled';
}
