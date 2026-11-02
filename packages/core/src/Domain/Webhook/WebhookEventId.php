<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Webhook;

use Lumineoss\Paystart\Core\Domain\Shared\PrefixedId;

final readonly class WebhookEventId extends PrefixedId
{
    public static function prefix(): string
    {
        return 'whk';
    }
}
