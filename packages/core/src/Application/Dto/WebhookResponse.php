<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Dto;

/**
 * What the HTTP layer should answer with.
 *
 * The status code is chosen here rather than in a bridge so every framework answers
 * identically. `body` is already safe to serialise: no payload echo, because echoing
 * a provider body back is how unverified input reaches a response.
 */
final readonly class WebhookResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
        public bool $shouldAlert = false,
    ) {
    }

    public static function accepted(): self
    {
        return new self(200, '{"status":"ok"}');
    }

    /**
     * A duplicate is a success from the provider's point of view. Answering with an
     * error would make it retry forever.
     */
    public static function duplicate(): self
    {
        return new self(200, '{"status":"duplicate"}');
    }

    /**
     * A rejected signature is either a forgery or a misconfigured secret. Both need a
     * non-2xx answer and an alert, and neither should get detail in the response.
     */
    public static function unauthorized(): self
    {
        return new self(401, '{"status":"unauthorized"}', true);
    }

    /**
     * The provider should retry. Used when our own storage or the state machine fails.
     *
     * There is deliberately no 422 variant: a payload we cannot interpret is recorded
     * and acknowledged, because a provider retrying a webhook it will never make
     * intelligible just delays every later notification.
     */
    public static function failed(): self
    {
        return new self(500, '{"status":"error"}', true);
    }
}
