<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use Lumineoss\Paystart\Core\Domain\Shared\IdGenerator;

/**
 * Identifier source for the standalone bridge.
 *
 * Time-ordered with a random tail, matching the ULID-style body the core's PrefixedId
 * accepts (lowercase base32, 26 chars). Time ordering matters here: identifiers are
 * primary keys in InnoDB, so an ordered key keeps inserts from fragmenting the B-tree
 * under load.
 *
 * This is not a substitute for the database's own uniqueness. A collision would be an
 * ordinary primary-key violation, which is a loud failure, not a silent overwrite.
 */
final readonly class UlidIdGenerator implements IdGenerator
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /**
     * @param (\Closure(): int)|null $clock millisecond source, injectable for tests
     */
    public function __construct(
        private ?\Closure $clock = null,
    ) {
    }

    public function generate(string $prefix): string
    {
        if (preg_match('/\A[a-z]{2,8}\z/', $prefix) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Identifier prefix must be 2 to 8 lowercase letters, got "%s".',
                $prefix,
            ));
        }

        $millis = $this->millis();

        $time = '';
        $remaining = $millis;

        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$remaining % 32] . $time;
            $remaining = intdiv($remaining, 32);
        }

        $random = '';

        for ($i = 0; $i < 16; $i++) {
            $random .= self::ALPHABET[random_int(0, 31)];
        }

        return $prefix . '_' . $time . $random;
    }

    private function millis(): int
    {
        if ($this->clock !== null) {
            return ($this->clock)();
        }

        return (int) floor(microtime(true) * 1000);
    }
}
