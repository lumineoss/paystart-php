<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

/**
 * Provider-agnostic payment method.
 *
 * A `kind` plus an optional channel keeps this stable across gateways: Midtrans
 * calls a bank transfer `bank_transfer` with `bank: bca`, Stripe calls it a
 * payment method type plus a bank, Xendit has its own channels. Those strings
 * live in adapter mapping tables, never here.
 */
final readonly class PaymentMethod
{
    public function __construct(
        public PaymentMethodKind $kind,
        public ?string $channel = null,
    ) {
        if ($channel !== null && preg_match('/\A[a-z0-9_-]{1,32}\z/', $channel) !== 1) {
            throw Exception\InvalidPaymentMethod::channel($channel);
        }
    }

    public static function card(): self
    {
        return new self(PaymentMethodKind::Card);
    }

    public static function bankTransfer(string $bank): self
    {
        return new self(PaymentMethodKind::BankTransfer, strtolower($bank));
    }

    public static function eWallet(string $provider): self
    {
        return new self(PaymentMethodKind::EWallet, strtolower($provider));
    }

    public static function qris(): self
    {
        return new self(PaymentMethodKind::QrCode, 'qris');
    }

    public static function retailOutlet(string $store): self
    {
        return new self(PaymentMethodKind::RetailOutlet, strtolower($store));
    }

    public function describe(): string
    {
        return $this->channel === null
            ? $this->kind->value
            : \sprintf('%s:%s', $this->kind->value, $this->channel);
    }
}
