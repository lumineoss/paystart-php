<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment\Payload;

use Lumineoss\Paystart\Core\Domain\Payment\InstructionPayload;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;

/**
 * A bank virtual account the payer transfers into.
 *
 * The account number is not a secret in the way a QR is, but it is still payer
 * specific: it is masked in audit output and never placed in a URL.
 */
final readonly class VirtualAccountPayload implements InstructionPayload
{
    public function __construct(
        public string $bank,
        public string $number,
        public ?string $accountName = null,
    ) {
        if (trim($bank) === '') {
            throw InvalidArgument::because('Virtual account bank must not be empty.');
        }

        // Digits only: providers issue numeric VAs, and accepting punctuation here
        // would let a malformed value reach a bank statement comparison later.
        if (preg_match('/\A\d{4,32}\z/', $number) !== 1) {
            throw InvalidArgument::because(\sprintf(
                'Virtual account number must be 4 to 32 digits, got "%s".',
                $number,
            ));
        }
    }

    public function maskedNumber(): string
    {
        $length = \strlen($this->number);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($this->number, -4);
    }

    public function describeForAudit(): string
    {
        return \sprintf('va(bank=%s,number=%s)', $this->bank, $this->maskedNumber());
    }
}
