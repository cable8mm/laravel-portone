<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Concerns;

use Cable8mm\LaravelPortone\CustomerRegistry;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentLedger;
use Cable8mm\LaravelPortone\PaymentOperations;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Billable
{
    public function hasPortOneId(): bool
    {
        return app(CustomerRegistry::class)->has($this);
    }

    public function portOneId(): ?string
    {
        return app(CustomerRegistry::class)->findId($this);
    }

    public function createOrGetPortOneCustomerId(): string
    {
        return app(CustomerRegistry::class)->ensureId($this);
    }

    public function preparePayment(int $amount, string $orderName): Payment
    {
        return app(PaymentOperations::class)->prepare($this, $amount, $orderName);
    }

    public function completePayment(string $paymentId): Payment
    {
        return app(PaymentOperations::class)->complete($this, $paymentId);
    }

    /**
     * @return MorphMany<Payment, $this>
     */
    public function payments(): MorphMany
    {
        return app(PaymentLedger::class)->payments($this);
    }

    public function findPayment(string $paymentId): ?Payment
    {
        return app(PaymentLedger::class)->find($this, $paymentId);
    }
}
