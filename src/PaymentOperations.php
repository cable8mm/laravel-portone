<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PaymentOperations
{
    public function __construct(
        private readonly CustomerRegistry $customers,
        private readonly PaymentLedger $payments,
    ) {}

    public function prepare(Model $billable, int $amount, string $orderName): Payment
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($orderName === '') {
            throw new \InvalidArgumentException('Payment order name must not be empty.');
        }

        return DB::transaction(function () use ($billable, $amount, $orderName): Payment {
            $customerId = $this->customers->ensureId($billable);

            return $this->payments->createPending($billable, $customerId, $amount, $orderName);
        });
    }
}
