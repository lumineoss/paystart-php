<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Bridge\Standalone\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Lumineoss\Paystart\Bridge\Standalone\Exception\PersistenceError;
use Lumineoss\Paystart\Core\Domain\Payment\Attempt;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptId;
use Lumineoss\Paystart\Core\Domain\Payment\AttemptStatus;
use Lumineoss\Paystart\Core\Domain\Payment\InstructionType;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\AuthChallengePayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\LinkPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrFormat;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\QrPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\RetailCodePayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payload\VirtualAccountPayload;
use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentInstruction;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethod;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentMethodKind;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentStatus;
use Lumineoss\Paystart\Core\Domain\Payment\Refund;
use Lumineoss\Paystart\Core\Domain\Payment\RefundStatus;
use Lumineoss\Paystart\Core\Domain\Shared\AggregateMeta;
use Lumineoss\Paystart\Core\Domain\Shared\Currency;
use Lumineoss\Paystart\Core\Domain\Shared\CustomerRef;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Domain\Shared\IdempotencyKey;
use Lumineoss\Paystart\Core\Domain\Shared\MerchantId;
use Lumineoss\Paystart\Core\Domain\Shared\Money;
use Lumineoss\Paystart\Core\Domain\Shared\RefundId;
use Lumineoss\Paystart\Core\Domain\Shared\Version;

/**
 * Rebuilds a Payment aggregate from its rows.
 *
 * Kept separate from the repository because hydration is the part with the subtle
 * bugs: an instruction payload that round-trips wrong, a status that maps to the wrong
 * case, a version read from the wrong row. Isolating it makes those testable without a
 * database if needed.
 *
 * Every row is inserted and read through the same class, so the payload encoding has
 * exactly one definition. Two independent encoders would drift.
 */
final readonly class PaymentHydrator
{
    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $attemptRows
     * @param array<string, list<array<string, mixed>>> $instructionRowsByAttempt
     * @param list<array<string, mixed>> $refundRows
     */
    public function hydrate(
        array $row,
        array $attemptRows,
        array $instructionRowsByAttempt,
        array $refundRows,
    ): Payment {
        $currency = Currency::of($this->requiredString($row, 'currency'));

        $attempts = [];

        foreach ($attemptRows as $attemptRow) {
            $attemptId = $this->requiredString($attemptRow, 'id');

            $attempts[] = Attempt::reconstitute(
                new AttemptId($attemptId),
                $this->method($this->requiredString($attemptRow, 'method_kind'), $this->nullableString($attemptRow, 'method_channel')),
                $this->time($this->requiredString($attemptRow, 'started_at')),
                $this->nullableString($attemptRow, 'gateway_reference') === null
                    ? null
                    : new GatewayReference($this->requiredString($attemptRow, 'gateway_reference')),
                $this->instructions($instructionRowsByAttempt[$attemptId] ?? [], $currency),
                AttemptStatus::from($this->requiredString($attemptRow, 'status')),
                $this->nullableString($attemptRow, 'failure_reason'),
            );
        }

        $refunds = [];

        foreach ($refundRows as $refundRow) {
            $refunds[] = new Refund(
                new RefundId($this->requiredString($refundRow, 'id')),
                Money::ofMinor($this->requiredInt($refundRow, 'amount_minor'), Currency::of($this->requiredString($refundRow, 'currency'))),
                new IdempotencyKey($this->requiredString($refundRow, 'idempotency_key')),
                $this->nullableString($refundRow, 'reason'),
                $this->nullableString($refundRow, 'gateway_reference') === null
                    ? null
                    : new GatewayReference($this->requiredString($refundRow, 'gateway_reference')),
                RefundStatus::from($this->requiredString($refundRow, 'status')),
            );
        }

        return Payment::reconstitute(
            new PaymentId($this->requiredString($row, 'id')),
            new MerchantId($this->requiredString($row, 'merchant_id')),
            Money::ofMinor($this->requiredInt($row, 'amount_minor'), $currency),
            $this->method($this->requiredString($row, 'method_kind'), $this->nullableString($row, 'method_channel')),
            $this->nullableString($row, 'customer_ref') === null
                ? null
                : new CustomerRef($this->requiredString($row, 'customer_ref')),
            $this->nullableString($row, 'description'),
            new AggregateMeta(
                new Version($this->requiredInt($row, 'version')),
                $this->time($this->requiredString($row, 'created_at')),
                $this->time($this->requiredString($row, 'updated_at')),
            ),
            PaymentStatus::from($this->requiredString($row, 'status')),
            $attempts,
            $refunds,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<PaymentInstruction>
     */
    private function instructions(array $rows, Currency $currency): array
    {
        $instructions = [];

        foreach ($rows as $row) {
            $instructions[] = new PaymentInstruction(
                InstructionType::from($this->requiredString($row, 'type')),
                $this->payload($this->requiredString($row, 'payload'), $this->requiredString($row, 'type')),
                $this->requiredBool($row, 'is_static'),
                $this->nullableString($row, 'expires_at') === null
                    ? null
                    : $this->time($this->requiredString($row, 'expires_at')),
                $this->nullableString($row, 'gateway_reference') === null
                    ? null
                    : new GatewayReference($this->requiredString($row, 'gateway_reference')),
                $this->nullableString($row, 'raw_provider_type'),
            );
        }

        return $instructions;
    }

    /**
     * Decodes a stored payload back into its concrete class.
     *
     * @return \Lumineoss\Paystart\Core\Domain\Payment\InstructionPayload
     */
    public function payload(string $json, string $type): object
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw PersistenceError::malformedPayload($type, $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw PersistenceError::malformedPayload($type, 'payload is not a JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return match ($type) {
            InstructionType::QrString->value => new QrPayload(
                $this->stringField($decoded, 'content', $type),
                QrFormat::String,
            ),
            InstructionType::QrImageUrl->value => QrPayload::fromImageUrl($this->stringField($decoded, 'content', $type)),
            InstructionType::RedirectUrl->value,
            InstructionType::HostedPaymentLink->value,
            InstructionType::Deeplink->value => new LinkPayload(
                $this->stringField($decoded, 'url', $type),
                (bool) ($decoded['singleUse'] ?? true),
                isset($decoded['platform']) && \is_string($decoded['platform']) ? $decoded['platform'] : null,
            ),
            InstructionType::VirtualAccount->value => new VirtualAccountPayload(
                $this->stringField($decoded, 'bank', $type),
                $this->stringField($decoded, 'number', $type),
                isset($decoded['accountName']) && \is_string($decoded['accountName']) ? $decoded['accountName'] : null,
            ),
            InstructionType::RetailCode->value => new RetailCodePayload(
                $this->stringField($decoded, 'store', $type),
                $this->stringField($decoded, 'code', $type),
            ),
            InstructionType::AuthChallenge3ds->value => new AuthChallengePayload(
                $this->stringField($decoded, 'redirectUrl', $type),
                $this->stringField($decoded, 'challengeToken', $type),
            ),
            default => throw PersistenceError::malformedPayload($type, 'no decoder for this instruction type'),
        };
    }

    /**
     * Encodes a payload for storage.
     *
     * Written next to the decoder on purpose: the two must agree, and separating them
     * across files is how a round-trip bug survives review.
     *
     * @return array<string, mixed>
     */
    public function encodePayload(\Lumineoss\Paystart\Core\Domain\Payment\InstructionPayload $payload): array
    {
        return match (true) {
            $payload instanceof QrPayload => ['content' => $payload->content],
            $payload instanceof LinkPayload => array_filter([
                'url' => $payload->url,
                'singleUse' => $payload->singleUse,
                'platform' => $payload->platform,
            ], static fn (mixed $v): bool => $v !== null),
            $payload instanceof VirtualAccountPayload => array_filter([
                'bank' => $payload->bank,
                'number' => $payload->number,
                'accountName' => $payload->accountName,
            ], static fn (mixed $v): bool => $v !== null),
            $payload instanceof RetailCodePayload => [
                'store' => $payload->store,
                'code' => $payload->code,
            ],
            $payload instanceof AuthChallengePayload => [
                'redirectUrl' => $payload->redirectUrl,
                'challengeToken' => $payload->challengeToken,
            ],
            default => throw PersistenceError::unsupportedPayload($payload::class),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringField(array $data, string $key, string $type): string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value)) {
            throw PersistenceError::malformedPayload($type, \sprintf('missing "%s"', $key));
        }

        return $value;
    }

    public function method(string $kind, ?string $channel): PaymentMethod
    {
        return new PaymentMethod(PaymentMethodKind::from($kind), $channel);
    }

    /**
     * Timestamps are stored as UTC and read back as UTC.
     *
     * Parsing without a timezone would silently interpret a stored UTC value in the
     * process default zone, which shifts every payment by the host's offset.
     */
    private function time(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        if ($parsed === false) {
            // Tolerate an ISO-8601 value too, which is what some drivers return.
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw PersistenceError::malformedRow($key, get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requiredString(array $row, string $key): string
    {
        return $this->nullableString($row, $key)
            ?? throw PersistenceError::missingColumn($key);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requiredInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        // SQLite returns integers as int; MySQL over PDO with emulated prepares can
        // return them as strings, so both are accepted rather than assuming one driver.
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        throw PersistenceError::malformedRow($key, get_debug_type($value));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requiredBool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value) || \is_string($value)) {
            return (bool) (int) $value;
        }

        throw PersistenceError::malformedRow($key, get_debug_type($value));
    }
}
