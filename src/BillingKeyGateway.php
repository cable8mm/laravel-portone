<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;

/**
 * PortOne 빌링키 API 호출 규격. 빌링키 조회·삭제와 빌링키 결제는 이 인터페이스를 통한다.
 *
 * 평문 빌링키는 호출 인자로만 들어오며, 어디에도 기록하지 않는다.
 */
interface BillingKeyGateway
{
    /**
     * PortOne 요청을 보낼 수 있는지(API Secret 설정) 확인한다. 아무 요청도 보내지 않는다.
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     */
    public function assertConfigured(): void;

    /**
     * PortOne에서 빌링키 정보를 조회한다(getBillingKeyInfo).
     *
     * @param  string  $billingKey  평문 빌링키
     * @return GatewayBillingKey 빌링키 상태, 발급 고객 ID, Issue ID
     *
     * @throws \Throwable PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function billingKeyInfo(#[\SensitiveParameter] string $billingKey): GatewayBillingKey;

    /**
     * PortOne에서 빌링키를 삭제한다(deleteBillingKey).
     *
     * @param  string  $billingKey  평문 빌링키
     * @param  string  $reason  삭제 사유
     *
     * @throws \Throwable PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function deleteBillingKey(#[\SensitiveParameter] string $billingKey, string $reason): void;

    /**
     * 빌링키로 원화 결제를 요청한다(payWithBillingKey). 결과는 결제 ID로 조회한다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $billingKey  평문 빌링키
     * @param  string  $orderName  주문명
     * @param  int  $amount  결제 금액(원)
     *
     * @throws \Throwable PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function payWithBillingKey(string $paymentId, #[\SensitiveParameter] string $billingKey, string $orderName, int $amount): void;
}
