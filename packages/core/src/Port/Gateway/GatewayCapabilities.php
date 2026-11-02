<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use Lumineoss\Paystart\Core\Domain\Payment\Capability;

/**
 * Capability discovery.
 *
 * Declared separately from the gateway port so a caller can ask "can this account
 * do partial refunds?" without instantiating a charge. Unsupported features must
 * fail with a clear exception at the call site, never be silently ignored.
 */
interface GatewayCapabilities
{
    public function supports(Capability $capability): bool;

    /**
     * @return list<Capability>
     */
    public function declare(): array;
}
