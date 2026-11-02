<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Payload;

use Lumineoss\Paystart\Core\Domain\Payment\InstructionPayload;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * A URL the payer is sent to: redirect, hosted payment page, or app deeplink.
 */
final readonly class LinkPayload implements InstructionPayload
{
    public function __construct(
        public string $url,
        public bool $singleUse = true,
        public ?string $platform = null,
    ) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw InvalidArgument::because('Instruction link is not a valid URL.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (!\in_array($scheme, ['http', 'https'], true) && $platform === null) {
            throw InvalidArgument::because(\sprintf(
                'Instruction link scheme "%s" is only allowed for a platform deeplink.',
                $scheme,
            ));
        }
    }

    public function describeForAudit(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        return \sprintf('link(scheme=%s,host=%s)', (string) parse_url($this->url, PHP_URL_SCHEME), \is_string($host) ? $host : '-');
    }
}
