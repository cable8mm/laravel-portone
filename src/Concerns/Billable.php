<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Concerns;

use Cable8mm\LaravelPortone\BillingKeyRegistry;
use Cable8mm\LaravelPortone\CustomerRegistry;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\BillingKeyNotIssuedException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\Exceptions\InvalidPaymentStatusException;
use Cable8mm\LaravelPortone\Exceptions\InvalidRefundAmountException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\BillingKey;
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
     * @throws DuplicatePaymentException 한 번만 결제되는 모델에 이미 결제된 레코드가 있어 이 결제를 자동 취소했을 때
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function completePayment(string $paymentId): Payment
    {
        return app(PaymentOperations::class)->complete($this, $paymentId);
    }

    /**
     * 이 모델이 한 번만 결제되는지(예: 주문) 선언한다. 기본값은 false이다.
     *
     * true를 반환하면 이 모델의 결제 확정은 모델 단위 배타 접근 안에서 이루어지고, 이미 PAID·PARTIALLY_CANCELLED·
     * CANCELLED인 결제가 있을 때 두 번째로 결제된 건은 PAID가 되지 않고 PortOne에서 자동 전액 취소된다
     * (DUPLICATE_CANCELLED, 취소 실패 시 DUPLICATE).
     * 웹훅·만료 검사처럼 결제 ID만으로 확정할 때는 결제 레코드의 billable_type·billable_id로 모델을 조회해 호출하며,
     * 모델을 찾을 수 없으면 false로 본다.
     */
    public function allowsOnlyOnePaidPayment(): bool
    {
        return false;
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

    /**
     * 빌링키 발급 기록을 미리 만든다. PortOne을 호출하지 않으며 상태는 PENDING이다.
     *
     * 반환한 기록의 issueParameters()를 브라우저 빌링키 발급(requestIssueBillingKey)에 넘긴다.
     *
     * @param  string  $issueName  발급 이름(PortOne issueName)
     * @return BillingKey 생성된 PENDING 빌링키 기록
     */
    public function prepareBillingKey(string $issueName): BillingKey
    {
        return app(PaymentOperations::class)->prepareBillingKey($this, $issueName);
    }

    /**
     * 브라우저 발급이 돌려준 빌링키를 PortOne에 확인하고 기록을 ISSUED로 바꾼다.
     *
     * 빌링키는 암호화해 저장한다. 같은 빌링키로 이미 ISSUED인 기록은 그대로 반환한다.
     *
     * @param  string  $issueId  prepareBillingKey()가 만든 기록의 Issue ID
     * @param  string  $billingKey  리다이렉트 쿼리의 billingKey
     * @return BillingKey ISSUED 빌링키 기록
     *
     * @throws BillingKeyNotFoundException 내 범위에 PENDING·FAILED·ISSUED 기록이 없을 때
     * @throws BillingKeyNotIssuedException PortOne이 이 고객에게 발급하지 않았다고 할 때(기록은 FAILED)
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function completeBillingKey(string $issueId, #[\SensitiveParameter] string $billingKey): BillingKey
    {
        return app(PaymentOperations::class)->completeBillingKey($this, $issueId, $billingKey);
    }

    /**
     * 이 모델의 빌링키 기록 관계(최신순)를 반환한다.
     *
     * @return MorphMany<BillingKey, $this> 생성일 내림차순 빌링키 기록 관계
     */
    public function billingKeys(): MorphMany
    {
        return app(BillingKeyRegistry::class)->billingKeys($this);
    }

    /**
     * ISSUED 빌링키를 PortOne에서 삭제하고 기록을 DELETED로 바꾼다.
     *
     * @param  string  $issueId  삭제할 빌링키 기록의 Issue ID
     * @param  string  $reason  PortOne에 전달할 삭제 사유
     * @return BillingKey DELETED 빌링키 기록
     *
     * @throws InvalidBillingKeyException 내 범위에 ISSUED 기록이 없을 때
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function deleteBillingKey(string $issueId, string $reason): BillingKey
    {
        return app(PaymentOperations::class)->deleteBillingKey($this, $issueId, $reason);
    }

    /**
     * ISSUED 빌링키로 결제하고 결제 레코드를 확정해 반환한다.
     *
     * 결제 레코드는 빌링키 기록을 가리킨다. PortOne이 결제를 거절하면 레코드는 PENDING으로 남는다.
     *
     * @param  string  $issueId  결제할 빌링키 기록의 Issue ID
     * @param  int  $amount  결제 금액(원, 0보다 커야 함)
     * @param  string  $orderName  주문명(빈 문자열 불가)
     * @return Payment 확정된 결제 레코드
     *
     * @throws \InvalidArgumentException 금액이 0 이하이거나 주문명이 비었을 때
     * @throws InvalidBillingKeyException 내 범위에 ISSUED 기록이 없을 때
     * @throws PaymentNotPaidException PortOne이 결제 완료로 표시하지 않았을 때
     * @throws AmountMismatchException 실결제액이 결제 금액과 다를 때
     * @throws DuplicatePaymentException 한 번만 결제되는 모델에 이미 결제된 레코드가 있을 때
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException payWithBillingKey가 실패했을 때 (원문 그대로 전달, 레코드는 PENDING)
     */
    public function chargeBillingKey(string $issueId, int $amount, string $orderName): Payment
    {
        return app(PaymentOperations::class)->chargeBillingKey($this, $issueId, $amount, $orderName);
    }
}
