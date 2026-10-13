<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\Payment;
use Illuminate\Database\Eloquent\Model;
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
     * PENDING이 아닌 레코드는 PortOne을 호출하지 않고 그대로 반환한다.
     * 로컬 저장 실패 시 같은 값으로 최대 3회까지 다시 시도한다.
     *
     * @param  Model  $billable  결제를 소유한 Billable 모델
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return Payment 확정된(또는 기존 상태 그대로인) 결제 레코드
     */
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
