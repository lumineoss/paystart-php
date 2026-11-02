<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

enum PaymentMethodKind: string
{
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case VirtualAccount = 'virtual_account';
    case EWallet = 'ewallet';
    case QrCode = 'qr_code';
    case RetailOutlet = 'retail_outlet';
    case DirectDebit = 'direct_debit';
    case PayLater = 'pay_later';

    /**
     * Whether the method settles asynchronously by default, which is what drives
     * the polling fallback for providers with delayed webhooks.
     */
    public function isAsynchronousByDefault(): bool
    {
        return \in_array($this, [
            self::BankTransfer,
            self::VirtualAccount,
            self::RetailOutlet,
            self::DirectDebit,
        ], true);
    }
}
