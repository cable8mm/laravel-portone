<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 결제 준비·확정·환불 흐름을 조율하는 서비스.
 *
 * Billable이 호출하는 진입점으로, 실제 상태 계산은 PaymentLifecycle,
 * 저장은 PaymentLedger, 외부 호출은 PaymentGateway가 맡는다.
 */
class PaymentOperations
{
    /**
     * @param  CustomerRegistry  $customers  고객 레코드 관리
     * @param  PaymentLedger  $payments  결제 레코드 저장소
     * @param  PaymentGateway  $gateway  PortOne API 호출
     * @param  PaymentLifecycle  $lifecycle  상태 전이 계산
     */
    public function __construct(
        private readonly CustomerRegistry $customers,
        private readonly PaymentLedger $payments,
        private readonly PaymentGateway $gateway,
        private readonly PaymentLifecycle $lifecycle,
    ) {}

    /**
     * 결제 레코드를 미리 만든다. PortOne을 호출하지 않는다.
     *
     * 고객 레코드 확보와 결제 생성을 하나의 트랜잭션으로 처리한다.
     * 실패하면 고객 레코드도 남지 않는다.
     *
     * @param  Model  $billable  결제를 소유할 Billable 모델
     * @param  int  $amount  결제 금액(원, 0보다 커야 함)
     * @param  string  $orderName  주문명(빈 문자열 불가)
     * @return Payment 생성된 PENDING 결제 레코드
     *
     * @throws \InvalidArgumentException 금액이 0 이하이거나 주문명이 비었을 때
     */
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

    /**
     * 결제를 확정한다. 레코드에 배타 접근을 걸고 PortOne 조회를 수행한다.
     *
     * PENDING·EXPIRED가 아닌 레코드는 PortOne을 호출하지 않고 그대로 반환한다.
     * EXPIRED 레코드는 만료 뒤에 결제된 경우를 복구하기 위해 다시 조회한다.
     * 실결제액이 준비 금액과 다르면 PortOne 결제를 전액 취소하고 결과를 저장한 뒤
     * AmountMismatchException을 던진다. 이미 금액 불일치로 저장된 레코드는 PortOne을
     * 다시 부르지 않고 같은 예외를 던진다.
     * 로컬 저장 실패 시 같은 값으로 최대 3회까지 다시 시도한다.
     *
     * @param  Model  $billable  결제를 소유한 Billable 모델
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return Payment 확정된(또는 기존 상태 그대로인) 결제 레코드
     *
     * @throws AmountMismatchException 실결제액이 준비 금액과 다를 때
     */
    public function complete(Model $billable, string $paymentId): Payment
    {
        $mismatch = null;

        $payment = $this->payments->withExclusiveAccess(
            $billable,
            $paymentId,
            function (Payment $payment) use (&$mismatch): Payment {
                if (in_array($payment->status, ['AMOUNT_MISMATCH_CANCELLED', 'AMOUNT_MISMATCH'], true)) {
                    $mismatch = AmountMismatchException::fromPayment($payment);

                    return $payment;
                }

                if (! in_array($payment->status, ['PENDING', 'EXPIRED'], true)) {
                    return $payment;
                }

                $gatewayPayment = $this->gateway->lookup($payment->payment_id);

                if ($this->lifecycle->isAmountMismatch($payment, $gatewayPayment)) {
                    $mismatch = $this->cancelAmountMismatch($payment, $gatewayPayment);

                    return $payment;
                }

                $outcome = $this->lifecycle->complete($payment, $gatewayPayment, now());

                return $this->write($payment, $outcome);
            },
        );

        // 잠금 트랜잭션 안에서 던지면 저장한 금액 불일치 결과가 롤백되므로, 커밋 뒤에 던진다.
        if ($mismatch !== null) {
            throw $mismatch;
        }

        return $payment;
    }

    /**
     * 오래된 PENDING 결제 1건을 PortOne에 확인해 PAID 또는 EXPIRED로 바꾼다.
     *
     * 레코드가 없거나 더 이상 PENDING이 아니면 아무것도 하지 않는다.
     * 판단할 수 없는 조회 결과면 그대로 두어 다음 만료 검사에서 다시 확인한다.
     * 실결제액이 준비 금액과 다르면 complete()와 같이 자동 전액 취소한다. 취소가 성공하면
     * 예외 없이 끝나고, 실패하면 AMOUNT_MISMATCH를 저장한 뒤 PortOne 예외를 전파한다.
     * 설정 누락·PortOne 실패·저장 실패는 감싸지 않고 전파한다.
     *
     * @param  string  $billableType  소유 Billable 모델의 morph 타입
     * @param  string  $billableId  소유 Billable 모델의 키
     * @param  string  $paymentId  PortOne 결제 식별자
     */
    public function expire(string $billableType, string $billableId, string $paymentId): void
    {
        $cancellationFailure = null;

        $this->payments->withExclusiveAccessByReference(
            $billableType,
            $billableId,
            $paymentId,
            function (Payment $payment) use (&$cancellationFailure): void {
                if ($payment->status !== 'PENDING') {
                    return;
                }

                $gatewayPayment = $this->gateway->lookupForExpiry($payment->payment_id);

                if ($this->lifecycle->isAmountMismatch($payment, $gatewayPayment)) {
                    $cancellationFailure = $this->cancelAmountMismatch($payment, $gatewayPayment)->getPrevious();

                    return;
                }

                $outcome = $this->lifecycle->expire($payment, $gatewayPayment, now());

                if ($outcome !== null) {
                    $this->write($payment, $outcome);
                }
            },
        );

        // AMOUNT_MISMATCH 저장이 롤백되지 않도록 커밋 뒤에 취소 실패를 전파한다.
        if ($cancellationFailure !== null) {
            throw $cancellationFailure;
        }
    }

    /**
     * PortOne 취소를 먼저 승인받은 뒤 로컬 레코드에 반영한다.
     *
     * 환불 결과는 PortOne 호출 전에 미리 계산되며, 로컬 저장 실패 시
     * 같은 값으로 최대 3회까지 다시 시도한다(PortOne 재호출 없음).
     *
     * @param  Model  $billable  결제를 소유한 Billable 모델
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $reason  PortOne에 전달할 환불 사유(빈 문자열 불가)
     * @param  int|null  $amount  환불 금액(원, null이면 전액)
     * @return Payment 환불이 반영된 결제 레코드
     */
    public function refund(Model $billable, string $paymentId, string $reason, ?int $amount = null): Payment
    {
        return $this->payments->withExclusiveAccess(
            $billable,
            $paymentId,
            function (Payment $payment) use ($paymentId, $reason, $amount): Payment {
                if ($reason === '') {
                    throw new \InvalidArgumentException('Payment refund reason must not be empty.');
                }

                $outcome = $this->lifecycle->refund($payment, $amount);
                $this->gateway->cancel($paymentId, $reason, $amount);

                return $this->write($payment, $outcome);
            },
        );
    }

    /**
     * 금액이 다른 결제를 PortOne에서 전액 취소하고 결과를 저장한다.
     *
     * 두 결과는 취소 요청 전에 계산한다. 취소가 실패해도 예외를 던지지 않고
     * AMOUNT_MISMATCH를 저장한 뒤, PortOne 예외를 previous로 담은 예외를 반환한다.
     *
     * @param  Payment  $payment  잠금을 잡은 PENDING 또는 EXPIRED 결제 레코드
     * @param  GatewayPayment  $gatewayPayment  금액이 다른 PAID 조회 결과
     * @return AmountMismatchException 호출자가 잠금이 끝난 뒤 던지거나 해석할 예외
     */
    private function cancelAmountMismatch(Payment $payment, GatewayPayment $gatewayPayment): AmountMismatchException
    {
        $outcomes = $this->lifecycle->amountMismatch($gatewayPayment);

        try {
            $this->gateway->cancel($payment->payment_id, $this->amountMismatchCancelReason(), null);
        } catch (Throwable $exception) {
            return AmountMismatchException::fromPayment($this->write($payment, $outcomes['failed']), $exception);
        }

        return AmountMismatchException::fromPayment($this->write($payment, $outcomes['cancelled']));
    }

    /**
     * 금액 불일치 자동 취소 사유(portone.amount_mismatch_cancel_reason)를 반환한다.
     */
    private function amountMismatchCancelReason(): string
    {
        $reason = config('portone.amount_mismatch_cancel_reason');

        return is_string($reason) && $reason !== '' ? $reason : '결제 금액 불일치로 자동 취소';
    }

    /**
     * 미리 계산된 결과를 저장한다. 실패하면 같은 값으로 최대 3회까지 시도한다.
     *
     * @param  Payment  $payment  갱신할 결제 레코드
     * @param  array{status: string, paid_at: Carbon}|array{status: string, cancelled_amount: int}|array{status: string, paid_amount: int, cancelled_amount?: int}|array{status: string}  $outcome  저장할 절대값
     * @return Payment 갱신된 결제 레코드
     */
    private function write(Payment $payment, array $outcome): Payment
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->payments->update($payment, $outcome);
            } catch (Throwable $exception) {
                if ($attempt >= 3) {
                    throw $exception;
                }
            }
        }
    }
}
