<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentOperations
{
    public function __construct(
        private readonly CustomerRegistry $customers,
        private readonly PaymentLedger $payments,
        private readonly PaymentGateway $gateway,
        private readonly PaymentLifecycle $lifecycle,
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

    public function complete(Model $billable, string $paymentId): Payment
    {
        return $this->payments->withExclusiveAccess(
            $billable,
            $paymentId,
            function (Payment $payment): Payment {
                if ($payment->status !== 'PENDING') {
                    return $payment;
                }

                $gatewayPayment = $this->gateway->lookup($payment->payment_id);
                $outcome = $this->lifecycle->complete($payment, $gatewayPayment, now());

                for ($attempt = 1; ; $attempt++) {
                    try {
                        return $this->payments->update($payment, $outcome);
                    } catch (Throwable $exception) {
                        if ($attempt >= 3) {
                            throw $exception;
                        }
                    }
                }
            },
        );
    }
}
