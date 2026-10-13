<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Support\Carbon;

class PaymentLifecycle
{
    /**
     * @return array{status: string, paid_at: Carbon}
     */
    public function complete(Payment $payment, GatewayPayment $gatewayPayment, Carbon $paidAt): array
    {
        if ($gatewayPayment->status !== 'PAID') {
            throw new PaymentNotPaidException('PortOne has not marked the payment as paid.');
        }

        if ($gatewayPayment->totalAmount !== $payment->amount) {
            throw new AmountMismatchException('The PortOne payment amount does not match the prepared amount.');
        }

        return [
            'status' => 'PAID',
            'paid_at' => $paidAt,
        ];
    }
}
