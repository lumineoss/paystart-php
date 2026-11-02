<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

/**
 * Identifies a merchant across contexts.
 *
 * Treated as an external identity: the library does not manage merchants, it
 * only needs to attribute money and configuration to one.
 */
final readonly class MerchantId extends PrefixedId
{
    public static function prefix(): string
    {
        return 'mer';
    }
}
