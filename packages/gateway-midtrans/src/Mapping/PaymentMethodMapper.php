<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Mapping;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethodKind;
use Lumineoss\Paystart\Gateway\Midtrans\Exception\UnsupportedPaymentMethod;

/**
 * Translates our method vocabulary into Midtrans `payment_type` values and back.
 *
 * Midtrans encodes method and channel in one flat object: a bank transfer is
 * `payment_type: bank_transfer` plus `bank_transfer: {bank: bca}`, an e-wallet is
 * `payment_type: gopay`, and a retail outlet is `payment_type: cstore` plus `store`.
 * All of that shape lives here so the domain never learns it.
 */
final readonly class PaymentMethodMapper
{
    /**
     * @return array<string, mixed> the method-specific fragment of a charge body
     */
    public function toChargeBody(PaymentMethod $method): array
    {
        return match ($method->kind) {
            PaymentMethodKind::QrCode => ['payment_type' => 'qris', 'qris' => ['acquirer' => 'gopay']],
            // `bank_transfer` and `virtual_account` are the same thing at Midtrans: a VA
            // number the payer transfers into. Both produce the same charge body.
            PaymentMethodKind::VirtualAccount, PaymentMethodKind::BankTransfer => $this->virtualAccount($method),
            PaymentMethodKind::EWallet => $this->eWallet($method),
            PaymentMethodKind::RetailOutlet => $this->retailOutlet($method),
            PaymentMethodKind::Card => ['payment_type' => 'credit_card'],
            default => throw UnsupportedPaymentMethod::kind($method->kind),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function virtualAccount(PaymentMethod $method): array
    {
        $bank = $method->channel ?? throw UnsupportedPaymentMethod::missingChannel('virtual_account');

        // Mandiri Bill is `echannel`, not `bank_transfer`, and has a different body.
        if ($bank === 'mandiri') {
            return [
                'payment_type' => 'echannel',
                'echannel' => [
                    'bill_info1' => 'Payment for order',
                    'bill_info2' => 'Online payment',
                ],
            ];
        }

        // Permata is spelled `permata` in Midtrans but the bank is BNI Permata; the
        // other three are plain BCA/BNI/BRI virtual accounts.
        $midtransBank = match ($bank) {
            'permata', 'bca', 'bni', 'bri' => $bank,
            default => throw UnsupportedPaymentMethod::channel($bank, 'virtual_account'),
        };

        return [
            'payment_type' => 'bank_transfer',
            'bank_transfer' => ['bank' => $midtransBank],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eWallet(PaymentMethod $method): array
    {
        return match ($method->channel) {
            'gopay' => ['payment_type' => 'gopay'],
            // ShopeePay is requested through the qris acquirer field, and the payment
            // type is still `qris`; Midtrans derives it from the acquirer.
            'shopeepay' => ['payment_type' => 'qris', 'qris' => ['acquirer' => 'airpay shopee']],
            default => throw UnsupportedPaymentMethod::channel($method->channel ?? '', 'ewallet'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function retailOutlet(PaymentMethod $method): array
    {
        $store = $method->channel ?? throw UnsupportedPaymentMethod::missingChannel('retail_outlet');

        $midtransStore = match ($store) {
            'indomaret' => 'indomaret',
            'alfamart' => 'alfamart',
            default => throw UnsupportedPaymentMethod::channel($store, 'retail_outlet'),
        };

        return [
            'payment_type' => 'cstore',
            'cstore' => ['store' => $midtransStore],
        ];
    }

    /**
     * Reconstructs our method from a Midtrans response body.
     *
     * Used when reading a status or a notification, where Midtrans reports only
     * `payment_type` and sometimes `bank` or `store`.
     */
    public function fromResponse(string $paymentType, ?string $bank = null, ?string $store = null): ?PaymentMethod
    {
        return match ($paymentType) {
            'qris' => PaymentMethod::qris(),
            'bank_transfer' => $bank === null ? null : PaymentMethod::bankTransfer($bank),
            'echannel' => PaymentMethod::bankTransfer('mandiri'),
            'gopay' => PaymentMethod::eWallet('gopay'),
            'shopeepay' => PaymentMethod::eWallet('shopeepay'),
            'cstore' => $store === null ? null : PaymentMethod::retailOutlet($store),
            'credit_card' => PaymentMethod::card(),
            // Unknown types are not guessed: a wrong method on a payment record is worse
            // than an absent one, because reporting groups by it.
            default => null,
        };
    }
}
