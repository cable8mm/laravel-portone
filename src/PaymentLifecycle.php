<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Exceptions\InvalidRefundAmountException;
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

    /**
     * @return array{status: string, cancelled_amount: int}
     */
    public function refund(Payment $payment, ?int $amount): array
    {
        if (! in_array($payment->status, ['PAID', 'PARTIALLY_CANCELLED'], true)) {
            throw new InvalidPaymentStatusException('Only paid payments can be refunded.');
        }

        $remainingAmount = $payment->amount - $payment->cancelled_amount;

        if ($amount !== null && ($amount <= 0 || $amount > $remainingAmount)) {
            throw new InvalidRefundAmountException('The refund amount must be positive and no greater than the remaining amount.');
        }

        $cancelledAmount = $amount === null
            ? $payment->amount
            : $payment->cancelled_amount + $amount;

        return [
            'status' => $cancelledAmount === $payment->amount ? 'CANCELLED' : 'PARTIALLY_CANCELLED',
            'cancelled_amount' => $cancelledAmount,
        ];
    }
}
