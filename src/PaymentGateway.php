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
     */
    public function lookup(string $paymentId): GatewayPayment;

    /**
     * PortOne 결제를 취소(환불)한다. 금액 생략 시 전액 취소이다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $reason  PortOne에 전달할 환불 사유
     * @param  int|null  $amount  환불 금액(원, null이면 전액)
     */
    public function cancel(string $paymentId, string $reason, ?int $amount): void;
}
