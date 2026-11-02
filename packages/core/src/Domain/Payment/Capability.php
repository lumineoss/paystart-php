<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Domain\Payment;

/**
 * What a gateway can do, independent of whether a particular account has it
 * enabled.
 *
 * Capability discovery is explicit so an unsupported feature fails loudly at the
 * call site instead of being silently ignored or quietly degraded.
 */
enum Capability: string
{
    /** Provider supports creating a payment without separate authorization. */
    case Charge = 'charge';

    /** Provider supports authorize-then-capture. */
    case Capture = 'capture';

    /** Provider supports voiding an authorization before capture. */
    case Void = 'void';

    /** Provider supports refunding less than the captured amount. */
    case PartialRefund = 'partial_refund';

    /** Provider returns a QR payload (dynamic, amount-bound). */
    case QrCode = 'qr_code';

    /** Provider issues a reusable, not-amount-bound QR. */
    case StaticQr = 'static_qr';

    /** Provider returns a hosted payment link. */
    case PaymentLink = 'payment_link';

    /** Provider returns an app deeplink. */
    case Deeplink = 'deeplink';

    /** Provider issues a virtual account number. */
    case VirtualAccount = 'virtual_account';

    /** Provider issues an offline retail payment code. */
    case RetailCode = 'retail_code';

    /** Provider can require a 3DS challenge. */
    case ThreeDsChallenge = 'three_ds_challenge';

    /** Provider supports recurring/subscription billing. */
    case Recurring = 'recurring';

    /** Provider supports installment plans. */
    case Installment = 'installment';
}
