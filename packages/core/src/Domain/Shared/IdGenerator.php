<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Shared;

/**
 * Random, human-readable identifier source.
 *
 * Injected rather than called statically so tests can produce predictable ids
 * and so determinism is possible in replay scenarios.
 */
interface IdGenerator
{
    /**
     * Generates the body of an identifier for the given prefix, e.g.
     * `pay_01j9x2k4m5n6p7q8r9s0t1u2v3`.
     */
    public function generate(string $prefix): string;
}
