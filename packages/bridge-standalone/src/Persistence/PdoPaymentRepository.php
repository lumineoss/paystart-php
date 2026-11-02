<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Lumineoss\Paystart\Core\Domain\Payment\Attempt;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\ConcurrencyConflict;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\Version;
use Lumineoss\Paystart\Core\Port\Persistence\PaymentRepository;
use PDO;

/**
 * PDO implementation of the payment repository.
 *
 * A save rewrites the child rows wholesale (attempts, instructions, refunds) rather
 * than diffing them. That is deliberate: the aggregate is small, the write path is
 * inside a transaction, and a diff would be a second place where "what changed" is
 * decided. Attempts and refunds are append-only in the domain, so the rows are stable
 * and the rewrite is cheap.
 *
 * Optimistic concurrency is enforced with a conditional UPDATE on `version`. If the
 * row was changed by someone else, zero rows match and the save fails loudly. Two
 * concurrent captures therefore cannot both succeed.
 */
final readonly class PdoPaymentRepository implements PaymentRepository
{
    public function __construct(
        private PDO $pdo,
        private PaymentHydrator $hydrator,
        private \Lumineoss\Paystart\Core\Domain\Shared\IdGenerator $ids,
    ) {
    }

    public function nextId(): PaymentId
    {
        return new PaymentId($this->ids->generate('pay'));
    }

    public function get(PaymentId $id): Payment
    {
        $payment = $this->find($id);

        if ($payment === null) {
            throw NotFound::aggregate('Payment', $id->value);
        }

        return $payment;
    }

    public function find(PaymentId $id): ?Payment
    {
        $statement = $this->pdo->prepare('SELECT * FROM paystart_payments WHERE id = :id');
        $statement->execute(['id' => $id->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        /** @var list<array<string, mixed>> $attemptRows */
        $attemptRows = $this->rows(
            'SELECT * FROM paystart_payment_attempts WHERE payment_id = :id ORDER BY position ASC',
            ['id' => $id->value],
        );

        $instructionsByAttempt = [];

        if ($attemptRows !== []) {
            $ids = array_map(
                static fn (array $r): string => self::stringColumn($r, 'id'),
                $attemptRows,
            );
            $placeholders = implode(',', array_fill(0, \count($ids), '?'));

            /** @var list<array<string, mixed>> $instructionRows */
            $instructionRows = $this->rows(
                \sprintf(
                    'SELECT * FROM paystart_payment_instructions WHERE attempt_id IN (%s) ORDER BY position ASC',
                    $placeholders,
                ),
                $ids,
            );

            foreach ($instructionRows as $instructionRow) {
                $instructionsByAttempt[self::stringColumn($instructionRow, 'attempt_id')][] = $instructionRow;
            }
        }

        /** @var list<array<string, mixed>> $refundRows */
        $refundRows = $this->rows(
            'SELECT * FROM paystart_payment_refunds WHERE payment_id = :id ORDER BY position ASC',
            ['id' => $id->value],
        );

        return $this->hydrator->hydrate($row, $attemptRows, $instructionsByAttempt, $refundRows);
    }

    /**
     * Looks a payment up by any of its provider references.
     *
     * A webhook carries only the provider's identifier, so this is the entry point that
     * routes it. The reference lives on the attempt, which is why this is a join rather
     * than a column on the payment.
     */
    public function findByGatewayReference(GatewayReference $reference): ?Payment
    {
        $statement = $this->pdo->prepare(
            'SELECT payment_id FROM paystart_payment_attempts WHERE gateway_reference = :reference LIMIT 1',
        );
        $statement->execute(['reference' => $reference->value]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!\is_array($row)) {
            return null;
        }

        return $this->find(new PaymentId(self::stringColumn($row, 'payment_id')));
    }

    public function save(Payment $payment): void
    {
        $meta = $payment->meta();
        $now = $this->format($meta->updatedAt);

        $existing = $this->pdo->prepare('SELECT version FROM paystart_payments WHERE id = :id');
        $existing->execute(['id' => $payment->id->value]);
        $current = $existing->fetchColumn();

        if ($current === false) {
            $this->insertRoot($payment, $now);
        } else {
            $this->updateRoot($payment, $now, (int) $current);
        }

        $this->writeAttempts($payment);
        $this->writeRefunds($payment);
    }

    private function insertRoot(Payment $payment, string $now): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO paystart_payments
                (id, merchant_id, currency, amount_minor, method_kind, method_channel,
                 customer_ref, description, status, version, created_at, updated_at)
             VALUES
                (:id, :merchant_id, :currency, :amount_minor, :method_kind, :method_channel,
                 :customer_ref, :description, :status, :version, :created_at, :updated_at)',
        );

        $statement->execute([
            'id' => $payment->id->value,
            'merchant_id' => $payment->merchantId->value,
            'currency' => $payment->amount->currency->code,
            'amount_minor' => $payment->amount->minorUnits,
            'method_kind' => $payment->method->kind->value,
            'method_channel' => $payment->method->channel,
            'customer_ref' => $payment->customer?->value,
            'description' => $payment->description,
            'status' => $payment->status()->value,
            'version' => $payment->meta()->version->number,
            'created_at' => $this->format($payment->meta()->createdAt),
            'updated_at' => $now,
        ]);
    }

    /**
     * Conditional update on the previously stored version.
     *
     * `WHERE version = :current` is the whole concurrency control: if another process
     * committed in between, no row matches and we refuse rather than overwrite. A
     * read-then-write check in PHP would race here.
     */
    private function updateRoot(Payment $payment, string $now, int $current): void
    {
        // The check is "stored version is older than the one being written", not
        // subtraction by one: a single save legitimately covers several domain
        // mutations (a refund plus the status transition it caused), so the version can
        // advance by more than one between reads. What must never happen is writing an
        // aggregate that was loaded from an already-superseded row.
        if ($current >= $payment->meta()->version->number) {
            throw ConcurrencyConflict::onSave(
                'Payment',
                new Version($payment->meta()->version->number),
                new Version($current),
            );
        }

        $statement = $this->pdo->prepare(
            'UPDATE paystart_payments
                SET merchant_id = :merchant_id,
                    currency = :currency,
                    amount_minor = :amount_minor,
                    method_kind = :method_kind,
                    method_channel = :method_channel,
                    customer_ref = :customer_ref,
                    description = :description,
                    status = :status,
                    version = :version,
                    updated_at = :updated_at
              WHERE id = :id AND version = :current_version',
        );

        $statement->execute([
            'merchant_id' => $payment->merchantId->value,
            'currency' => $payment->amount->currency->code,
            'amount_minor' => $payment->amount->minorUnits,
            'method_kind' => $payment->method->kind->value,
            'method_channel' => $payment->method->channel,
            'customer_ref' => $payment->customer?->value,
            'description' => $payment->description,
            'status' => $payment->status()->value,
            'version' => $payment->meta()->version->number,
            'updated_at' => $now,
            'id' => $payment->id->value,
            'current_version' => $current,
        ]);

        if ($statement->rowCount() === 0) {
            throw ConcurrencyConflict::onSave(
                'Payment',
                new Version($payment->meta()->version->number),
                new Version($current),
            );
        }
    }

    private function writeAttempts(Payment $payment): void
    {
        $position = 0;

        foreach ($payment->attempts() as $attempt) {
            $this->upsertAttempt($payment->id, $attempt, $position);
            $this->writeInstructions($attempt);
            $position++;
        }
    }

    private function upsertAttempt(PaymentId $paymentId, Attempt $attempt, int $position): void
    {
        $exists = $this->pdo->prepare('SELECT id FROM paystart_payment_attempts WHERE id = :id');
        $exists->execute(['id' => $attempt->id->value]);

        if ($exists->fetchColumn() !== false) {
            // Attempts only move forward through their own status, so an update is the
            // right shape; the identity and start time never change.
            $statement = $this->pdo->prepare(
                'UPDATE paystart_payment_attempts
                    SET status = :status, gateway_reference = :gateway_reference, failure_reason = :failure_reason
                  WHERE id = :id',
            );

            $statement->execute([
                'status' => $attempt->status()->value,
                'gateway_reference' => $attempt->gatewayReference?->value,
                'failure_reason' => $attempt->failureReason(),
                'id' => $attempt->id->value,
            ]);

            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO paystart_payment_attempts
                (id, payment_id, position, method_kind, method_channel, status,
                 gateway_reference, failure_reason, started_at)
             VALUES
                (:id, :payment_id, :position, :method_kind, :method_channel, :status,
                 :gateway_reference, :failure_reason, :started_at)',
        );

        $statement->execute([
            'id' => $attempt->id->value,
            'payment_id' => $paymentId->value,
            'position' => $position,
            'method_kind' => $attempt->method->kind->value,
            'method_channel' => $attempt->method->channel,
            'status' => $attempt->status()->value,
            'gateway_reference' => $attempt->gatewayReference?->value,
            'failure_reason' => $attempt->failureReason(),
            'started_at' => $this->format($attempt->startedAt),
        ]);
    }

    private function writeInstructions(Attempt $attempt): void
    {
        $position = 0;

        foreach ($attempt->instructions() as $instruction) {
            $encoded = json_encode(
                $this->hydrator->encodePayload($instruction->payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );

            $exists = $this->pdo->prepare('SELECT id FROM paystart_payment_instructions WHERE attempt_id = :id AND position = :position');
            $exists->execute(['id' => $attempt->id->value, 'position' => $position]);

            if ($exists->fetchColumn() !== false) {
                // Instructions are immutable, so an existing row is left alone. Rewriting
                // it would erase what the payer was actually shown.
                $position++;

                continue;
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO paystart_payment_instructions
                    (id, attempt_id, position, type, is_static, expires_at,
                     gateway_reference, raw_provider_type, payload)
                 VALUES
                    (:id, :attempt_id, :position, :type, :is_static, :expires_at,
                     :gateway_reference, :raw_provider_type, :payload)',
            );

            $statement->execute([
                // Deterministic id keeps a retried save from inserting a second copy.
                'id' => \sprintf('%s-%02d', $attempt->id->value, $position),
                'attempt_id' => $attempt->id->value,
                'position' => $position,
                'type' => $instruction->type->value,
                'is_static' => $instruction->isStatic ? 1 : 0,
                'expires_at' => $instruction->expiresAt === null ? null : $this->format($instruction->expiresAt),
                'gateway_reference' => $instruction->gatewayReference?->value,
                'raw_provider_type' => $instruction->rawProviderType,
                'payload' => $encoded,
            ]);

            $position++;
        }
    }

    private function writeRefunds(Payment $payment): void
    {
        $position = 0;

        foreach ($payment->refunds() as $refund) {
            $exists = $this->pdo->prepare('SELECT id FROM paystart_payment_refunds WHERE id = :id');
            $exists->execute(['id' => $refund->id->value]);

            if ($exists->fetchColumn() !== false) {
                $statement = $this->pdo->prepare('UPDATE paystart_payment_refunds SET status = :status WHERE id = :id');
                $statement->execute(['status' => $refund->status()->value, 'id' => $refund->id->value]);
                $position++;

                continue;
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO paystart_payment_refunds
                    (id, payment_id, position, currency, amount_minor, status,
                     idempotency_key, reason, gateway_reference, created_at)
                 VALUES
                    (:id, :payment_id, :position, :currency, :amount_minor, :status,
                     :idempotency_key, :reason, :gateway_reference, :created_at)',
            );

            $statement->execute([
                'id' => $refund->id->value,
                'payment_id' => $payment->id->value,
                'position' => $position,
                'currency' => $refund->amount->currency->code,
                'amount_minor' => $refund->amount->minorUnits,
                'status' => $refund->status()->value,
                'idempotency_key' => $refund->idempotencyKey->value,
                'reason' => $refund->reason,
                'gateway_reference' => $refund->gatewayReference?->value,
                'created_at' => $this->format($payment->meta()->updatedAt),
            ]);

            $position++;
        }
    }

    /**
     * @param array<string, mixed>|list<string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringColumn(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!\is_string($value)) {
            throw new \Lumineoss\Paystart\Bridge\Standalone\Exception\PersistenceError(
                \sprintf('Column "%s" is not a string.', $key),
            );
        }

        return $value;
    }

    /**
     * Stores UTC in a format every supported driver reads back identically.
     */
    private function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
