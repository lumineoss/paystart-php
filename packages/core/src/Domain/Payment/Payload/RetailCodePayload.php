<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Payload;

use Lumineoss\Paystart\Core\Domain\Payment\InstructionPayload;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * An offline payment code redeemable at a retail chain (Indomaret, Alfamart).
 */
final readonly class RetailCodePayload implements InstructionPayload
{
    public function __construct(
        public string $store,
        public string $code,
    ) {
        if (trim($store) === '') {
            throw InvalidArgument::because('Retail store must not be empty.');
        }

        if (preg_match('/\A[A-Za-z0-9-]{4,32}\z/', $code) !== 1) {
            throw InvalidArgument::because(\sprintf(
                'Retail payment code must be 4 to 32 alphanumerics, got "%s".',
                $code,
            ));
        }
    }

    public function describeForAudit(): string
    {
        return \sprintf('retail(store=%s)', $this->store);
    }
}
