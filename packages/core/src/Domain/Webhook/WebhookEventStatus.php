<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Webhook;

/**
 * Processing state of a recorded webhook.
 */
enum WebhookEventStatus: string
{
    /** Stored, not yet applied. */
    case Received = 'received';

    /** Applied to the aggregate successfully. */
    case Processed = 'processed';

    /** Applied before, or intentionally not applicable. */
    case Ignored = 'ignored';

    /** Applying raised; retained so it can be replayed after a fix. */
    case Failed = 'failed';
}
