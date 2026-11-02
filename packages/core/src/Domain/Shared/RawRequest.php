<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

/**
 * A framework-neutral view of an incoming HTTP request.
 *
 * Deliberately free of PSR-7: the domain must not know about transport types. The
 * port layer provides the PSR-7 conversion (see Port\Webhook\RawRequestFactory).
 *
 * The body must be the raw bytes exactly as received. Re-encoding JSON changes key
 * order and whitespace, which breaks both the HMAC headers and Midtrans's SHA-512
 * of concatenated fields.
 */
final readonly class RawRequest
{
    public function __construct(
        public string $body,
        /** @var array<string, string> lowercased header name => value */
        public array $headers = [],
        public string $method = 'POST',
        public string $path = '/',
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function create(string $body, array $headers = [], string $method = 'POST', string $path = '/'): self
    {
        $normalised = [];

        foreach ($headers as $name => $value) {
            $normalised[strtolower($name)] = $value;
        }

        return new self($body, $normalised, $method, $path);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
