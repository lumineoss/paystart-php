<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Port\Gateway;

use Lumineoss\Paystart\Core\Domain\Payment\PaymentResult;
use Lumineoss\Paystart\Core\Domain\Payment\RefundResult;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;

/**
 * The single abstraction every gateway adapter implements.
 *
 * Rules that hold for every implementation:
 *   - a raw provider payload never crosses this boundary;
 *   - every mutating call takes an idempotency key and must honour it;
 *   - unsupported operations throw UnsupportedCapability rather than degrading;
 *   - statuses returned here are our PaymentStatus, already mapped.
 */
interface PaymentGatewayPort extends GatewayCapabilities
{
    /**
     * Identifier of the provider, matching the webhook route segment, e.g.
     * "midtrans", "xendit", "stripe".
     */
    public function name(): string;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability
     */
    public function createPayment(CreatePaymentRequest $request): PaymentResult;

    /**
     * Authoritative status lookup, used as the polling fallback for providers
     * whose webhooks are delayed.
     */
    public function getStatus(GatewayReference $reference): GatewayStatusSnapshot;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability
     */
    public function capture(CaptureRequest $request): PaymentResult;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability
     */
    public function refund(RefundRequest $request): RefundResult;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability
     */
    public function cancel(CancelRequest $request): PaymentResult;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability
     */
    public function createSubscription(CreateSubscriptionRequest $request): SubscriptionResult;

    /**
     * @throws \Lumineoss\Paystart\Core\Domain\Payment\Exception\UnsupportedCapability
     */
    public function disableSubscription(GatewayReference $subscriptionReference): void;
}
