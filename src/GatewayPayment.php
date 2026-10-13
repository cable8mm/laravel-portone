<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

final class GatewayPayment
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $totalAmount,
    ) {}
}
