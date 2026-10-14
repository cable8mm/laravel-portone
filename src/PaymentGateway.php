<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

/**
 * PortOne API 호출 규격. 모든 외부 요청은 이 인터페이스를 통한다.
 */
interface PaymentGateway
{
    /**
     * PortOne에서 결제 1건을 조회한다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return GatewayPayment PortOne 결제 상태와 실결제액
     *
     * @throws \Throwable PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function lookup(string $paymentId): GatewayPayment;

    /**
     * 만료 확인용으로 결제 1건을 조회한다.
     *
     * lookup()과 같지만, PortOne이 결제를 모르면(PAYMENT_NOT_FOUND) 예외 대신
     * found가 false인 결과를 반환한다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return GatewayPayment PortOne 결제 상태와 실결제액
     *
     * @throws \Throwable 그 밖의 PortOne API 호출 실패 (원문 그대로 전달)
     */
    public function lookupForExpiry(string $paymentId): GatewayPayment;

    /**
     * PortOne 결제를 취소(환불)한다. 금액 생략 시 전액 취소이다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $reason  PortOne에 전달할 환불 사유
     * @param  int|null  $amount  환불 금액(원, null이면 전액)
     *
     * @throws \Throwable PortOne 호출 실패 시 원인을 그대로 전파
     */
    public function cancel(string $paymentId, string $reason, ?int $amount): void;
}
