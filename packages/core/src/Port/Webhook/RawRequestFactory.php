<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Webhook;

use Lumineoss\Paystart\Core\Domain\Shared\RawRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Converts a PSR-7 request into the domain's RawRequest.
 *
 * Lives in the port layer because this is where PSR-7 is permitted: the domain
 * itself must not know about transport types.
 */
final class RawRequestFactory
{
    public static function fromPsr7(ServerRequestInterface $request): RawRequest
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return RawRequest::create(
            (string) $request->getBody(),
            $headers,
            $request->getMethod(),
            $request->getUri()->getPath(),
        );
    }
}
