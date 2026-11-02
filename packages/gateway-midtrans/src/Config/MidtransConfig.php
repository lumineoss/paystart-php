<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Config;

use Lumineoss\Paystart\Gateway\Midtrans\Exception\MidtransConfigInvalid;

/**
 * Midtrans credentials and endpoint selection.
 *
 * The server key is a secret that can move money. It is therefore never
 * serialisable: this object deliberately has no toArray(), no jsonSerialize(), and
 * a __debugInfo() that redacts it, so an accidental var_dump or a struct logger
 * cannot leak it.
 */
final readonly class MidtransConfig
{
    public function __construct(
        public string $serverKey,
        public string $clientKey,
        public bool $isProduction = false,
        public ?string $merchantId = null,
    ) {
        if (trim($serverKey) === '') {
            throw MidtransConfigInvalid::missingServerKey();
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $serverKey) === 1) {
            throw MidtransConfigInvalid::malformedServerKey();
        }
    }

    public function baseUrl(): string
    {
        return $this->isProduction
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    /**
     * Midtrans authenticates with HTTP Basic using the server key as the username
     * and an empty password.
     */
    public function authorizationHeader(): string
    {
        return 'Basic ' . base64_encode($this->serverKey . ':');
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function __debugInfo(): array
    {
        return [
            'serverKey' => '[redacted]',
            'clientKey' => $this->clientKey === '' ? '' : '[redacted]',
            'isProduction' => $this->isProduction,
            'merchantId' => $this->merchantId,
        ];
    }
}
