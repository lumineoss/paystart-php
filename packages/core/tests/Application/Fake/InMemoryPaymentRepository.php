<?php

declare(strict_types=1);

namespace Lumineoss\Paystart\Core\Tests\Application\Fake;

use Lumineoss\Paystart\Core\Domain\Payment\Payment;
use Lumineoss\Paystart\Core\Domain\Payment\PaymentId;
use Lumineoss\Paystart\Core\Domain\Shared\Exception\NotFound;
use Lumineoss\Paystart\Core\Domain\Shared\GatewayReference;
use Lumineoss\Paystart\Core\Port\Persistence\PaymentRepository;

final class InMemoryPaymentRepository implements PaymentRepository
{
    /** @var array<string, Payment> */
    private array $payments = [];

    private int $sequence = 0;

    public function nextId(): PaymentId
    {
        $this->sequence++;

        return new PaymentId(\sprintf('pay_%026d', $this->sequence));
    }

    public function get(PaymentId $id): Payment
    {
        return $this->payments[$id->value] ?? throw NotFound::aggregate('Payment', $id->value);
    }

    public function find(PaymentId $id): ?Payment
    {
        return $this->payments[$id->value] ?? null;
    }

    public function findByGatewayReference(GatewayReference $reference): ?Payment
    {
        foreach ($this->payments as $payment) {
            foreach ($payment->attempts() as $attempt) {
                if ($attempt->gatewayReference?->equals($reference) === true) {
                    return $payment;
                }
            }
        }

        return null;
    }

    public function save(Payment $payment): void
    {
        $this->payments[$payment->id->value] = $payment;
    }
}
