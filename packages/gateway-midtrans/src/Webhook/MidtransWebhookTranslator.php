<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Gateway\Midtrans\Webhook;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\InvalidArgument;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Webhook\DeduplicationKey;
use Lumineoss\Paystart\Core\Port\Webhook\VerifiedWebhook;
use Lumineoss\Paystart\Core\Port\Webhook\WebhookTranslatorPort;
use Lumineoss\Paystart\Gateway\Midtrans\Amount\MidtransAmountScale;

/**
 * Maps Midtrans notification vocabulary onto ours.
 *
 * Kept separate from verification so a mapping mistake cannot weaken the signature
 * check. Only a verified webhook ever reaches this class.
 *
 * The mapping table is the whole point of the anti-corruption layer: `capture` and
 * `settlement` are different words for money being collected, and `deny`, `cancel` and
 * `expire` all end the payment but are not the same business event.
 */
final readonly class MidtransWebhookTranslator implements WebhookTranslatorPort
{
    public function __construct(
        private MidtransAmountScale $amountScale = new MidtransAmountScale(),
    ) {
    }

    public function eventCategory(VerifiedWebhook $webhook): string
    {
        $status = $webhook->payload['transaction_status'] ?? null;

        if (!\is_string($status)) {
            throw InvalidArgument::because('Midtrans notification is missing transaction_status.');
        }

        // Refund and chargeback notifications describe money moving back out, not the
        // original charge, so they are their own category rather than a status change on
        // the payment's own lifecycle.
        return match ($status) {
            'refund', 'partial_refund' => 'refund.status',
            'chargeback', 'partial_chargeback' => 'chargeback.status',
            default => 'payment.status',
        };
    }

    public function impliedStatus(VerifiedWebhook $webhook): ?PaymentStatus
    {
        $status = $webhook->payload['transaction_status'] ?? null;

        if (!\is_string($status)) {
            throw InvalidArgument::because('Midtrans notification is missing transaction_status.');
        }

        return match ($status) {
            // `pending` arrives for a card charge awaiting completion but also as a
            // re-notification for a QR that is still unpaid. Both mean "no new outcome".
            'pending' => PaymentStatus::Pending,
            // `capture` is authorised with fraud_status=accept for cards; a `challenge`
            // fraud_status keeps it authorised pending review rather than moving it on.
            'capture' => PaymentStatus::Authorized,
            'settlement' => PaymentStatus::Settled,
            'deny' => PaymentStatus::Failed,
            'cancel' => PaymentStatus::Canceled,
            'expire' => PaymentStatus::Expired,
            'failure' => PaymentStatus::Failed,
            // Refund and chargeback notifications are applied by the refund path, which
            // needs the amount and its own idempotency key. Reporting a status here
            // would move the payment without recording the money that moved.
            default => null,
        };
    }

    public function deduplicationKey(VerifiedWebhook $webhook): DeduplicationKey
    {
        return new DeduplicationKey('midtrans', $webhook->externalEventId);
    }

    public function impliedAmount(VerifiedWebhook $webhook): ?Money
    {
        $gross = $webhook->payload['gross_amount'] ?? null;

        if ($gross === null) {
            return null;
        }

        $currencyCode = $webhook->payload['currency'] ?? 'IDR';

        if (!\is_string($currencyCode)) {
            throw InvalidArgument::because('Midtrans notification has a non-string currency.');
        }

        // Parsed through the decimal-string path because Midtrans sends "275000.00"
        // here while the charge request used 275000.
        $grossString = match (true) {
            \is_string($gross) => $gross,
            \is_int($gross) => (string) $gross,
            default => throw InvalidArgument::because('Midtrans notification has a non-numeric gross_amount.'),
        };

        return $this->amountScale->fromDecimalString($grossString, Currency::of($currencyCode));
    }
}
