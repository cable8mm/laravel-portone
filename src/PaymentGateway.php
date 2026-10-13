<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

interface PaymentGateway
{
    public function lookup(string $paymentId): GatewayPayment;

    public function cancel(string $paymentId, string $reason, ?int $amount): void;
}
