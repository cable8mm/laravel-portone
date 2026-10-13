<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Concerns;

use Cable8mm\LaravelPortone\CustomerRegistry;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Exceptions\InvalidRefundAmountException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\Payment;
use Cable8mm\LaravelPortone\PaymentLedger;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 결제 기능을 부여하는 Eloquent 모델용 트레이트.
 *
 * Cashier 스타일 API의 진입점으로, 금액 단위는 원(KRW) 정수이다.
 * 모든 연산은 호출한 모델의 Billable 범위 안에서만 동작한다.
 */
trait Billable
{
    /**
     * 이 모델에 연결된 PortOne 고객 ID가 있는지 확인한다.
     *
     * @return bool 고객 레코드가 있으면 true
     */
    public function hasPortOneId(): bool
    {
        return app(CustomerRegistry::class)->has($this);
    }

    /**
     * 이 모델에 연결된 PortOne 고객 ID를 조회한다.
     *
     * @return string|null 고객 ID, 없으면 null
     */
    public function portOneId(): ?string
    {
        return app(CustomerRegistry::class)->findId($this);
    }

    /**
     * 이 모델의 PortOne 고객 ID를 만들거나 기존 값을 반환한다.
     *
     * @return string 고객 ID (UUID 문자열)
     */
    public function createOrGetPortOneCustomerId(): string
    {
        return app(CustomerRegistry::class)->ensureId($this);
    }

    /**
     * 결제 레코드를 미리 만든다. PortOne을 호출하지 않으며 상태는 PENDING이다.
     *
     * @param  int  $amount  결제 금액(원, 0보다 커야 함)
     * @param  string  $orderName  주문명(빈 문자열 불가)
     * @return Payment 생성된 PENDING 결제 레코드
     *
     * @throws \InvalidArgumentException 금액이 0 이하이거나 주문명이 비었을 때
     */
    public function preparePayment(int $amount, string $orderName): Payment
    {
        return app(PaymentOperations::class)->prepare($this, $amount, $orderName);
    }

    /**
     * 구매자가 브라우저에서 결제한 뒤 서버에서 검증하고 확정한다.
     *
     * PortOne 조회 결과가 결제 완료이고 금액이 일치하면 PAID가 된다.
     * 이미 PENDING이 아닌 레코드는 그대로 반환한다.
     *
     * @param  string  $paymentId  확정할 결제의 PortOne 결제 식별자
     * @return Payment 확정된(또는 기존 상태 그대로인) 결제 레코드
     *
     * @throws PaymentNotFoundException 내 범위에 해당 결제가 없을 때
     * @throws PaymentNotPaidException PortOne에서 미결제로 조회될 때
     * @throws AmountMismatchException 서버 금액과 PortOne 실결제액이 다를 때
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function completePayment(string $paymentId): Payment
    {
        return app(PaymentOperations::class)->complete($this, $paymentId);
    }

    /**
     * 결제를 환불한다. 금액을 생략하면 전액 환불이다.
     *
     * 전액 환불 시 CANCELLED, 일부 환불 시 PARTIALLY_CANCELLED가 된다.
     *
     * @param  string  $paymentId  환불할 결제의 PortOne 결제 식별자
     * @param  string  $reason  PortOne에 전달할 환불 사유(빈 문자열 불가)
     * @param  int|null  $amount  환불 금액(원, null이면 전액)
     * @return Payment 환불이 반영된 결제 레코드
     *
     * @throws PaymentNotFoundException 내 범위에 해당 결제가 없을 때
     * @throws InvalidPaymentStatusException 미결제 건을 환불하려 할 때
     * @throws InvalidRefundAmountException 환불 금액이 0 이하이거나 잔액을 초과할 때
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     * @throws \InvalidArgumentException 환불 사유가 비었을 때
     */
    public function refund(string $paymentId, string $reason, ?int $amount = null): Payment
    {
        return app(PaymentOperations::class)->refund($this, $paymentId, $reason, $amount);
    }

    /**
     * 이 모델의 결제 목록 관계(최신순)를 반환한다.
     *
     * @return MorphMany<Payment, $this> 생성일 내림차순 결제 관계
     */
    public function payments(): MorphMany
    {
        return app(PaymentLedger::class)->payments($this);
    }

    /**
     * 내 범위에서 결제 1건을 찾는다. 다른 모델의 결제는 조회되지 않는다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return Payment|null 결제 레코드, 없으면 null
     */
    public function findPayment(string $paymentId): ?Payment
    {
        return app(PaymentLedger::class)->find($this, $paymentId);
    }
}
