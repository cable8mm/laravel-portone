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
 * 결제 확정·환불·만료·취소 동기화의 상태 전이를 순수하게 계산하는 클래스.
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
     * @return array{status: string, paid_at: Carbon, paid_amount: int} 저장할 확정 값
     *
     * @throws PaymentNotPaidException PortOne 상태가 PAID가 아닐 때
     * @throws AmountMismatchException 실결제액을 알 수 없을 때(실결제액이 다르면 호출 전에 isAmountMismatch()로 걸러진다)
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
            'paid_amount' => $gatewayPayment->totalAmount,
        ];
    }

    /**
     * PortOne이 결제 완료라고 했는데 실결제액이 준비 금액과 다른지 판단한다.
     *
     * 실결제액을 알 수 없으면(null) 불일치로 보지 않는다. 기록할 수 없는 금액으로 취소하지 않기 위해서다.
     *
     * @param  Payment  $payment  확인할 결제 레코드
     * @param  GatewayPayment  $gatewayPayment  PortOne 조회 결과
     */
    public function isAmountMismatch(Payment $payment, GatewayPayment $gatewayPayment): bool
    {
        return $gatewayPayment->status === 'PAID'
            && $gatewayPayment->totalAmount !== null
            && $gatewayPayment->totalAmount !== $payment->amount;
    }

    /**
     * 금액 불일치 자동 취소의 두 결과를 PortOne 취소 요청 전에 계산한다.
     *
     * 취소 성공이면 AMOUNT_MISMATCH_CANCELLED(실결제액 전액 취소), 실패면 AMOUNT_MISMATCH이다.
     * 준비 금액(amount)과 paid_at은 바꾸지 않는다.
     *
     * @param  GatewayPayment  $gatewayPayment  isAmountMismatch()가 true인 PortOne 조회 결과
     * @return array{cancelled: array{status: string, paid_amount: int, cancelled_amount: int}, failed: array{status: string, paid_amount: int}} 취소 성공·실패 때 저장할 값
     */
    public function amountMismatch(GatewayPayment $gatewayPayment): array
    {
        $paidAmount = (int) $gatewayPayment->totalAmount;

        return [
            'cancelled' => [
                'status' => 'AMOUNT_MISMATCH_CANCELLED',
                'paid_amount' => $paidAmount,
                'cancelled_amount' => $paidAmount,
            ],
            'failed' => [
                'status' => 'AMOUNT_MISMATCH',
                'paid_amount' => $paidAmount,
            ],
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
     * @return array{status: string, paid_at: Carbon, paid_amount: int}|array{status: string}|null 저장할 값, 변경이 없으면 null
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
                'paid_amount' => $gatewayPayment->totalAmount,
            ];
        }

        return null;
    }

    /**
     * PortOne 취소 상태를 로컬 레코드에 맞추는 결과를 계산한다(취소 동기화).
     *
     * PAID·PARTIALLY_CANCELLED 레코드는 PortOne CANCELLED(취소 총액 = amount)면 CANCELLED,
     * PARTIAL_CANCELLED(0 < 취소 총액 < amount, 기존 cancelled_amount 이상)면 PARTIALLY_CANCELLED가 된다.
     * AMOUNT_MISMATCH 레코드는 PortOne CANCELLED(취소 총액 = paid_amount)면 AMOUNT_MISMATCH_CANCELLED가 된다.
     * 그 밖의 조합(PortOne이 아직 PAID, 취소 총액 없음 등)은 변경 없음(null)이다.
     *
     * @param  Payment  $payment  확인할 결제 레코드
     * @param  GatewayPayment  $gatewayPayment  PortOne 조회 결과
     * @return array{status: string, cancelled_amount: int}|null 저장할 값, 변경이 없으면 null
     */
    public function syncCancellation(Payment $payment, GatewayPayment $gatewayPayment): ?array
    {
        $cancelledAmount = $gatewayPayment->cancelledAmount;

        if ($cancelledAmount === null) {
            return null;
        }

        if (in_array($payment->status, ['PAID', 'PARTIALLY_CANCELLED'], true)) {
            if ($gatewayPayment->status === 'CANCELLED' && $cancelledAmount === $payment->amount) {
                return ['status' => 'CANCELLED', 'cancelled_amount' => $cancelledAmount];
            }

            if ($gatewayPayment->status === 'PARTIAL_CANCELLED'
                && $cancelledAmount > 0
                && $cancelledAmount < $payment->amount
                && $cancelledAmount >= $payment->cancelled_amount) {
                return ['status' => 'PARTIALLY_CANCELLED', 'cancelled_amount' => $cancelledAmount];
            }

            return null;
        }

        if ($payment->status === 'AMOUNT_MISMATCH'
            && $gatewayPayment->status === 'CANCELLED'
            && $cancelledAmount === $payment->paid_amount) {
            return ['status' => 'AMOUNT_MISMATCH_CANCELLED', 'cancelled_amount' => $cancelledAmount];
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
