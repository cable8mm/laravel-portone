<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Models\Payment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentLedger
{
    /**
     * @template TBillable of Model
     *
     * @param  TBillable  $billable
     * @return MorphMany<Payment, TBillable>
     */
    public function payments(Model $billable): MorphMany
    {
        return $billable->morphMany(Payment::class, 'billable')
            ->orderByDesc('created_at');
    }

    public function find(Model $billable, string $paymentId): ?Payment
    {
        return $this->payments($billable)
            ->where('payment_id', $paymentId)
            ->first();
    }

    /**
     * @param  Closure(Payment): Payment  $operation
     */
    public function withExclusiveAccess(Model $billable, string $paymentId, Closure $operation): Payment
    {
        return DB::transaction(function () use ($billable, $paymentId, $operation): Payment {
            $payment = $this->payments($billable)
                ->where('payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw new PaymentNotFoundException('The payment does not belong to this Billable model.');
            }

            return $operation($payment);
        });
    }

    /**
     * @param  array{status: string, paid_at: Carbon}  $values
     */
    public function update(Payment $payment, array $values): Payment
    {
        DB::transaction(function () use ($payment, $values): void {
            $payment->forceFill($values);
            $payment->save();
        });

        return $payment;
    }

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
