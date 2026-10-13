<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Concerns;

use Cable8mm\LaravelPortone\CustomerRegistry;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentOperations;

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
}
