<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentLedger
{
    public function createPending(Model $billable, string $customerId, int $amount, string $orderName): Payment
    {
        while (true) {
            $paymentId = (string) Str::uuid();

            try {
                return Payment::query()->create([
                    'payment_id' => $paymentId,
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $this->billableId($billable),
                    'customer_id' => $customerId,
                    'order_name' => $orderName,
                    'amount' => $amount,
                    'status' => 'PENDING',
                    'cancelled_amount' => 0,
                    'paid_at' => null,
                ]);
            } catch (QueryException $exception) {
                $paymentIdExists = DB::table('portone_payments')
                    ->where('payment_id', $paymentId)
                    ->exists();

                if (! $paymentIdExists) {
                    throw $exception;
                }
            }
        }
    }

    private function billableId(Model $billable): string
    {
        $key = $billable->getKey();

        if (! is_string($key) && ! is_int($key)) {
            throw new \InvalidArgumentException('A Billable model must have a string or integer key.');
        }

        return (string) $key;
    }
}
