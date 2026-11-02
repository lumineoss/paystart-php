<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Tests\Unit;

use Lumineoss\Paystart\Bridge\Standalone\Persistence\PdoWebhookInbox;
use Lumineoss\Paystart\Bridge\Standalone\Persistence\UlidIdGenerator;
use Lumineoss\Paystart\Bridge\Standalone\Tests\Support\PaymentFixture;
use Lumineoss\Paystart\Bridge\Standalone\Tests\Support\SqliteDatabase;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEvent;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventId;
use Lumineoss\Paystart\Core\Domain\Webhook\WebhookEventStatus;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdoWebhookInboxTest extends TestCase
{
    private PDO $pdo;

    private PdoWebhookInbox $inbox;

    protected function setUp(): void
    {
        $this->pdo = SqliteDatabase::create();
        $this->inbox = new PdoWebhookInbox($this->pdo, new UlidIdGenerator());
    }

    private function event(
        string $dedup = 'midtrans:order03:200:settlement',
        ?string $id = null,
        string $provider = 'midtrans',
        string $eventType = 'settlement',
    ): WebhookEvent {
        return new WebhookEvent(
            new WebhookEventId($id ?? $this->inbox->nextId()->value),
            $provider,
            'order03:200:settlement',
            $dedup,
            $eventType,
            'order03',
            ['transaction_status' => $eventType],
            PaymentFixture::time(),
        );
    }

    #[Test]
    public function it_claims_an_event_the_first_time(): void
    {
        $event = $this->event();

        self::assertNotNull($this->inbox->claim($event));
        self::assertSame($event->id->value, $this->inbox->get($event->id)->id->value);
    }

    /**
     * The whole purpose of the inbox. A second delivery of the same event must be
     * reported as "someone else got here first", not applied again.
     */
    #[Test]
    public function a_second_claim_of_the_same_key_returns_null(): void
    {
        self::assertNotNull($this->inbox->claim($this->event()));

        $second = $this->inbox->claim($this->event());

        self::assertNull($second, 'a duplicate delivery must not be claimable');
    }

    /**
     * The duplicate is refused by the unique index, not by a check in PHP, so the count
     * proves the database held the line.
     */
    #[Test]
    public function a_duplicate_leaves_exactly_one_row(): void
    {
        $this->inbox->claim($this->event());
        $this->inbox->claim($this->event());
        $this->inbox->claim($this->event());

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_webhook_events')->fetchColumn();

        self::assertSame(1, $count);
    }

    /**
     * The first delivery's payload is what actually arrived, so a later duplicate must
     * not overwrite it.
     */
    #[Test]
    public function the_original_payload_survives_a_duplicate_delivery(): void
    {
        $first = $this->event();
        $this->inbox->claim($first);

        $second = new WebhookEvent(
            new WebhookEventId($this->inbox->nextId()->value),
            'midtrans',
            'order03:200:settlement',
            $first->deduplicationKey,
            'settlement',
            'order03',
            ['transaction_status' => 'tampered'],
            PaymentFixture::time(),
        );

        self::assertNull($this->inbox->claim($second));

        $stored = $this->inbox->get($first->id);
        self::assertSame(['transaction_status' => 'settlement'], $stored->payload);
    }

    /**
     * A unique violation must be distinguished from every other database failure. If a
     * connection error were reported as "duplicate", the provider would stop retrying an
     * event that was never stored.
     */
    #[Test]
    public function a_non_integrity_failure_is_not_reported_as_a_duplicate(): void
    {
        $this->pdo->exec('DROP TABLE paystart_webhook_events');

        $this->expectException(\PDOException::class);

        $this->inbox->claim($this->event());
    }

    #[Test]
    public function a_different_key_is_a_different_event(): void
    {
        $this->inbox->claim($this->event('midtrans:order03:200:settlement'));
        $this->inbox->claim($this->event('midtrans:order03:202:expire'));

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_webhook_events')->fetchColumn();

        self::assertSame(2, $count);
    }

    /**
     * The unique index is scoped by provider as well as key. A key that fails to embed
     * its provider must still not let one provider's event suppress another's.
     */
    #[Test]
    public function two_providers_sharing_a_bare_key_do_not_collide(): void
    {
        $this->inbox->claim($this->event('shared-key', provider: 'midtrans'));
        $this->inbox->claim($this->event('shared-key', provider: 'xendit'));

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM paystart_webhook_events')->fetchColumn();

        self::assertSame(2, $count);
    }

    #[Test]
    public function the_same_provider_and_key_still_collides(): void
    {
        $this->inbox->claim($this->event('shared-key', provider: 'midtrans'));

        self::assertNull($this->inbox->claim($this->event('shared-key', provider: 'midtrans')));
    }

    #[Test]
    public function it_round_trips_a_claimed_event(): void
    {
        $event = $this->event();
        $this->inbox->claim($event);

        $loaded = $this->inbox->get($event->id);

        self::assertSame('midtrans', $loaded->provider);
        self::assertSame('order03:200:settlement', $loaded->externalEventId);
        self::assertSame('settlement', $loaded->eventType);
        self::assertSame('order03', $loaded->gatewayReference);
        self::assertSame(WebhookEventStatus::Received, $loaded->status());
        self::assertSame(
            $event->receivedAt->getTimestamp(),
            $loaded->receivedAt->getTimestamp(),
        );
    }

    #[Test]
    public function it_persists_a_processed_status(): void
    {
        $event = $this->event();
        $this->inbox->claim($event);

        $event->markProcessed(PaymentFixture::time());
        $this->inbox->save($event);

        self::assertSame(WebhookEventStatus::Processed, $this->inbox->get($event->id)->status());
    }

    #[Test]
    public function it_persists_a_failure_with_its_reason(): void
    {
        $event = $this->event();
        $this->inbox->claim($event);

        $event->markFailed('status machine refused the transition');
        $this->inbox->save($event);

        $loaded = $this->inbox->get($event->id);

        self::assertSame(WebhookEventStatus::Failed, $loaded->status());
        self::assertSame('status machine refused the transition', $loaded->failureReason());
    }

    /**
     * A failed event stays claimable by nobody, but it must remain visible so a replayer
     * can find it. `hasProcessed` is the query that separates the two.
     */
    #[Test]
    public function has_processed_is_false_for_a_failed_event(): void
    {
        $event = $this->event();
        $this->inbox->claim($event);
        $event->markFailed('boom');
        $this->inbox->save($event);

        self::assertFalse($this->inbox->hasProcessed('midtrans', $event->deduplicationKey));
    }

    #[Test]
    public function has_processed_is_true_for_a_completed_event(): void
    {
        $event = $this->event();
        $this->inbox->claim($event);
        $event->markProcessed(PaymentFixture::time());
        $this->inbox->save($event);

        self::assertTrue($this->inbox->hasProcessed('midtrans', $event->deduplicationKey));
    }

    #[Test]
    public function has_processed_is_false_for_an_unknown_key(): void
    {
        self::assertFalse($this->inbox->hasProcessed('midtrans', 'never-seen'));
    }

    #[Test]
    public function an_event_ignored_as_a_duplicate_is_not_processed(): void
    {
        $event = $this->event();
        $this->inbox->claim($event);
        $event->markIgnored('duplicate of an earlier delivery');
        $this->inbox->save($event);

        self::assertFalse($this->inbox->hasProcessed('midtrans', $event->deduplicationKey));
        self::assertSame('duplicate of an earlier delivery', $this->inbox->get($event->id)->failureReason());
    }

    #[Test]
    public function get_throws_for_an_unknown_id(): void
    {
        $this->expectException(NotFound::class);

        $this->inbox->get(new WebhookEventId('whk_01j9x2k4m5n6p7q8r9s0t1u2v9'));
    }

    #[Test]
    public function next_id_produces_a_value_the_domain_accepts(): void
    {
        self::assertMatchesRegularExpression('/\Awhk_[0-9a-z]{8,64}\z/', $this->inbox->nextId()->value);
    }
}
