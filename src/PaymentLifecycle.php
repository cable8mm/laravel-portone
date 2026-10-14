<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Exceptions\InvalidRefundAmountException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Support\Carbon;

/**
 * 결제 확정·환불·만료의 상태 전이를 순수하게 계산하는 클래스.
 *
 * DB도 PortOne도 건드리지 않으며, 저장할 절대값만 반환한다.
 */
class PaymentLifecycle
{
    /**
     * 확정 결과를 계산한다. PortOne 결제 완료 + 금액 일치가 조건이다.
     *
     * @param  Payment  $payment  확정할 PENDING 또는 EXPIRED 결제 레코드
     * @param  GatewayPayment  $gatewayPayment  PortOne 조회 결과
     * @param  Carbon  $paidAt  paid_at에 기록할 시각
     * @return array{status: string, paid_at: Carbon} 저장할 확정 값
     *
     * @throws PaymentNotPaidException PortOne 상태가 PAID가 아닐 때
     * @throws AmountMismatchException 실결제액이 준비 금액과 다를 때
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
     * 만료 확인 결과를 계산한다.
     *
     * 결제 완료 + 금액 일치면 PAID, 확인 시점에 결제되지 않은 결과(PAYMENT_NOT_FOUND,
     * READY, FAILED — T-021 관측과 소유자 결정)면 EXPIRED, 그 밖에는 변경 없음(null)이다.
     *
     * @param  Payment  $payment  확인할 PENDING 결제 레코드
     * @param  GatewayPayment  $gatewayPayment  만료 확인용 PortOne 조회 결과
     * @param  Carbon  $paidAt  PAID일 때 paid_at에 기록할 시각
     * @return array{status: string, paid_at: Carbon}|array{status: string}|null 저장할 값, 변경이 없으면 null
     */
    public function expire(Payment $payment, GatewayPayment $gatewayPayment, Carbon $paidAt): ?array
    {
        if (! $gatewayPayment->found || in_array($gatewayPayment->status, ['READY', 'FAILED'], true)) {
            return ['status' => 'EXPIRED'];
        }

        if ($gatewayPayment->status === 'PAID' && $gatewayPayment->totalAmount === $payment->amount) {
            return [
                'status' => 'PAID',
                'paid_at' => $paidAt,
            ];
        }

        return null;
    }

    /**
     * 환불 결과를 계산한다. 전액이면 CANCELLED, 일부면 PARTIALLY_CANCELLED이다.
     *
     * @param  Payment  $payment  환불할 결제 레코드(PAID 또는 PARTIALLY_CANCELLED)
     * @param  int|null  $amount  환불 금액(원, null이면 전액)
     * @return array{status: string, cancelled_amount: int} 저장할 환불 값
     *
     * @throws InvalidPaymentStatusException 환불 불가 상태에서 호출했을 때
     * @throws InvalidRefundAmountException 환불 금액이 0 이하이거나 잔액을 초과할 때
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
