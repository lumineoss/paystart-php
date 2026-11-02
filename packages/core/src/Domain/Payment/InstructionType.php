<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

/**
 * The payer-facing next action a gateway returned.
 *
 * The type and the `isStatic` flag are separate dimensions on purpose: the same
 * instruction type can be reusable (a static QRIS code) or single-use and
 * amount-bound (a dynamic one). Encoding that in the enum would double every case.
 */
enum InstructionType: string
{
    case RedirectUrl = 'redirect_url';
    case HostedPaymentLink = 'hosted_payment_link';
    case QrString = 'qr_string';
    case QrImageUrl = 'qr_image_url';
    case Deeplink = 'deeplink';
    case VirtualAccount = 'virtual_account';
    case RetailCode = 'retail_code';
    case AuthChallenge3ds = 'auth_challenge_3ds';

    public function isLinkLike(): bool
    {
        return \in_array($this, [
            self::RedirectUrl,
            self::HostedPaymentLink,
            self::Deeplink,
        ], true);
    }

    public function isQrLike(): bool
    {
        return \in_array($this, [
            self::QrString,
            self::QrImageUrl,
        ], true);
    }

    /**
     * Whether the payload is bearer-like: whoever holds it can attempt to pay.
     *
     * Used by the redaction processor and by the rule that these values are never
     * written to an indexable location or returned by an unauthenticated endpoint.
     */
    public function isBearerLike(): bool
    {
        return $this->isLinkLike() || $this->isQrLike() || $this === self::RetailCode;
    }
}
