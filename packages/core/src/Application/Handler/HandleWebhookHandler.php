<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Application\Handler;

use Lumineoss\Paystart\Core\Application\Dto\WebhookResponse;
use Lumineoss\Paystart\Core\Domain\Audit\AuditAction;
use Lumineoss\Paystart\Core\Domain\Audit\AuditEntry;
use Lumineoss\Paystart\Core\Domain\Payment\Event\PaymentStatusChanged;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\InvalidPaymentTransition;
use Lumineoss\Paystart\Core\Domain\Payment\Exception\PaymentAmountImmutable;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStateMachine;
use Lumineoss\Paystart\Core\Domain\Shared\Clock;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\PaystartException;
use Lumineoss\Paystart\Core\Domain\Shared\IdGenerator;
use Lumineoss\Paystart\Core\Domain\Shared\RawRequest;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEvent;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventStatus;
use Lumineoss\Paystart\Core\Port\Persistence\AuditRepository;
use Lumineoss\Paystart\Core\Port\Persistence\OutboxRepository;
use Lumineoss\Paystart\Core\Port\Persistence\PaymentRepository;
use Lumineoss\Paystart\Core\Port\Persistence\TransactionManagerPort;
use Lumineoss\Paystart\Core\Port\Persistence\WebhookInbox;
use Lumineoss\Paystart\Core\Port\Webhook\Exception\InvalidWebhookSignature;
use Lumineoss\Paystart\Core\Port\Webhook\Exception\WebhookVerificationUnavailable;
use Lumineoss\Paystart\Core\Port\Webhook\VerifiedWebhook;
use Lumineoss\Paystart\Core\Port\Webhook\WebhookTranslatorPort;
use Lumineoss\Paystart\Core\Port\Webhook\WebhookVerifierPort;
use Psr\Log\LoggerInterface;

/**
 * Receives a provider webhook.
 *
 * The order of operations is the security model, so it is fixed and not configurable:
 *
 *   1. verify the signature against the raw bytes. Nothing else touches the body
 *      before this, because reading a field first is how a forger learns whether a
 *      payload is worth signing;
 *   2. claim the event in the inbox, atomically. A duplicate stops here and returns
 *      2xx, so the provider stops retrying;
 *   3. apply the state change, the outbox rows and the audit entry in one
 *      transaction;
 *   4. answer with a status code chosen once, here, so every bridge agrees.
 *
 * A verification failure is never a 2xx. Accepting an unverified webhook because a
 * secret was missing is exactly how a forged "settlement" gets through.
 */
final readonly class HandleWebhookHandler
{
    public function __construct(
        private WebhookVerifierPort $verifier,
        private WebhookTranslatorPort $translator,
        private WebhookInbox $inbox,
        private PaymentRepository $payments,
        private OutboxRepository $outbox,
        private AuditRepository $audit,
        private TransactionManagerPort $transactions,
        private PaymentStateMachine $machine,
        private Clock $clock,
        private IdGenerator $ids,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RawRequest $request): WebhookResponse
    {
        $now = $this->clock->now();

        try {
            $webhook = $this->verifier->verify($request);
        } catch (InvalidWebhookSignature $e) {
            // Deliberately not logging the body: it is attacker-controlled and may
            // contain card data.
            $this->logger->warning('Webhook signature rejected.', [
                'provider_path' => $request->path,
                'reason' => $e->getMessage(),
            ]);

            $this->auditRejection($request, AuditAction::WebhookRejected, $e->getMessage(), $now);

            return WebhookResponse::unauthorized();
        } catch (WebhookVerificationUnavailable $e) {
            $this->logger->error('Webhook verification unavailable; failing closed.', [
                'reason' => $e->getMessage(),
            ]);

            return WebhookResponse::failed();
        }

        $deduplicationKey = $this->translator->deduplicationKey($webhook);

        $event = new WebhookEvent(
            $this->inbox->nextId(),
            $webhook->provider,
            $webhook->eventType,
            $deduplicationKey->value,
            $this->translator->eventCategory($webhook),
            $webhook->reference->value,
            $webhook->payload,
            $now,
        );

        $claimed = $this->inbox->claim($event);

        if ($claimed === null) {
            $this->audit->append(new AuditEntry(
                AuditAction::WebhookDuplicateIgnored,
                $now,
                null,
                null,
                'provider',
                $webhook->provider,
                ['deduplicationKey' => $event->deduplicationKey, 'eventType' => $event->eventType],
            ));

            return WebhookResponse::duplicate();
        }

        try {
            return $this->transactions->transactional(
                fn (): WebhookResponse => $this->apply($claimed, $webhook, $now),
            );
        } catch (PaystartException | InvalidPaymentTransition | PaymentAmountImmutable $e) {
            $this->logger->error('Webhook could not be applied; retained for replay.', [
                'webhookEventId' => $claimed->id->value,
                'eventType' => $claimed->eventType,
                'reason' => $e->getMessage(),
            ]);

            $claimed->markFailed($e->getMessage());
            $this->inbox->save($claimed);

            return WebhookResponse::failed();
        }
    }

    private function apply(WebhookEvent $event, VerifiedWebhook $webhook, \DateTimeImmutable $now): WebhookResponse
    {
        $implied = $this->translator->impliedStatus($webhook);

        if ($implied === null) {
            // A category we record but do not act on yet, e.g. a refund confirmation
            // that is applied by a different handler. Recorded, not dropped.
            $event->markIgnored('No payment status implied by this event category.');

            return $this->complete($event, $now, WebhookEventStatus::Ignored);
        }

        $payment = $this->payments->findByGatewayReference($webhook->reference);

        if ($payment === null) {
            // Not necessarily an error: Midtrans can notify for a payment created
            // outside this library, and a create response may not have been persisted
            // yet. Recorded so it can be reconciled, and answered with 2xx so the
            // provider stops retrying.
            $event->markIgnored('No local payment for this gateway reference.');

            $this->audit->append(new AuditEntry(
                AuditAction::WebhookReceived,
                $now,
                null,
                null,
                'provider',
                $webhook->provider,
                [
                    'outcome' => 'unmatched_reference',
                    'gatewayReference' => $webhook->reference->value,
                    'eventType' => $event->eventType,
                ],
            ));

            return $this->complete($event, $now, WebhookEventStatus::Ignored);
        }

        // A provider that reports a different amount is a reconciliation incident, not
        // a status update. Failing closed keeps the ledger intact and raises the alarm.
        $reportedAmount = $this->translator->impliedAmount($webhook);

        if ($reportedAmount !== null) {
            $payment->assertAmountMatches($reportedAmount);
        }

        $previous = $payment->status();

        if ($previous === $implied) {
            // Re-application is a no-op. This is what makes an out-of-order or
            // duplicated delivery harmless without needing perfect deduplication.
            $event->markIgnored('Payment is already in the implied state.');

            return $this->complete($event, $now, WebhookEventStatus::Ignored);
        }

        $payment->transitionTo($implied, $this->machine, $now);

        // Refund events move money back, and the aggregate must record them or the
        // refundable remainder drifts from the provider.
        $this->payments->save($payment);

        $this->outbox->append(PaymentStatusChanged::from(
            $payment,
            $previous,
            $this->ids->generate('evt'),
            $now,
            $webhook->externalEventId,
        ));

        $this->audit->append(new AuditEntry(
            AuditAction::WebhookReceived,
            $now,
            $payment->merchantId->value,
            $payment->id->value,
            'provider',
            $webhook->provider,
            [
                'eventType' => $event->eventType,
                'from' => $previous->value,
                'to' => $implied->value,
                'webhookEventId' => $event->id->value,
            ],
            $webhook->externalEventId,
        ));

        return $this->complete($event, $now, WebhookEventStatus::Processed);
    }

    private function complete(WebhookEvent $event, \DateTimeImmutable $now, WebhookEventStatus $status): WebhookResponse
    {
        if ($status === WebhookEventStatus::Processed) {
            $event->markProcessed($now);
        }

        $this->inbox->save($event);

        return WebhookResponse::accepted();
    }

    private function auditRejection(RawRequest $request, AuditAction $action, string $reason, \DateTimeImmutable $now): void
    {
        try {
            $this->audit->append(new AuditEntry(
                $action,
                $now,
                null,
                null,
                'provider',
                null,
                ['path' => $request->path, 'reason' => $reason],
            ));
        } catch (\Throwable) {
            // An audit sink failure must not change the response to a rejected
            // webhook: it is already a rejection, and answering 2xx would be worse.
        }
    }
}
