<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

/**
 * Lifecycle of a single Attempt.
 *
 * An attempt is one provider interaction to satisfy a payment. Retries and
 * refreshed instructions create a new attempt; they never reopen an old one, so
 * the audit trail always shows exactly what the payer was shown and when it
 * stopped being valid.
 */
enum AttemptStatus: string
{
    /** Created at the provider; the payer may act on the instruction. */
    case AwaitingAction = 'awaiting_action';

    /** A provider call is in flight or the provider is asynchronously processing. */
    case Processing = 'processing';

    /** Funds reserved but not collected. */
    case Authorized = 'authorized';

    /** Funds collected by this attempt. */
    case Succeeded = 'succeeded';

    /** Provider declined, errored, or the payer failed authentication. */
    case Failed = 'failed';

    /** Lifetime elapsed, or superseded by a newer attempt. */
    case Expired = 'expired';

    /** Ending the instruction at our request. */
    case Canceled = 'canceled';

    public function isLive(): bool
    {
        return \in_array($this, [
            self::AwaitingAction,
            self::Processing,
            self::Authorized,
        ], true);
    }

    public function isFinal(): bool
    {
        return !$this->isLive();
    }
}
