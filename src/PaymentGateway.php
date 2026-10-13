<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

interface PaymentGateway
{
    public function lookup(string $paymentId): GatewayPayment;
}
