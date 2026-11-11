<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\PortonePhp\Client\PortOneClient;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Cable8mm\PortonePhp\Model\BillingKeyInfo;
use Cable8mm\PortonePhp\Model\Payment as PaymentModel;

/**
 * cable8mm/portone-php 클라이언트로 PortOne V2 API를 호출하는 게이트웨이.
 *
 * 패키지의 모든 PortOne 요청은 여기서 나간다. 빌링키 결제의 통화(원화)도 여기서 정한다.
 */
class PortOneGateway implements BillingKeyGateway, PaymentGateway
{
    /** 빌링키 결제 통화 */
    private const CURRENCY = 'KRW';

    /**
     * PortOne에서 결제 1건을 조회한다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return GatewayPayment PortOne 결제 상태, 실결제액, 취소 총액, 영수증 URL
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function lookup(string $paymentId): GatewayPayment
    {
        $payment = $this->client()->payment->getPayment($paymentId);

        return $this->gatewayPayment($payment->variant, $payment->data);
    }

    /**
     * PortOne 결제 응답을 조회 결과로 바꾼다. 금액과 영수증 URL은 portone-php Payment 접근자로 읽는다.
     *
     * @param  string  $status  PortOne 결제 상태(응답의 status)
     * @param  array<string, mixed>  $data  디코딩한 PortOne 응답 본문
     * @return GatewayPayment PortOne 결제 상태, 실결제액, 취소 총액, 영수증 URL
     */
    protected function gatewayPayment(string $status, array $data): GatewayPayment
    {
        $payment = new PaymentModel($data, $status, true);
        $receiptUrl = $payment->receiptUrl();

        return new GatewayPayment(
            $payment->variant,
            $payment->totalAmount(),
            cancelledAmount: $payment->cancelledAmount(),
            receiptUrl: $receiptUrl === '' ? null : $receiptUrl,
        );
    }

    /**
     * PortOne 결제 조회 응답을 받은 그대로 반환한다. 테스트용 응답 기록 등에 쓴다.
     *
     * 응답에는 고객 개인정보와 카드·계좌 정보가 들어 있을 수 있다. 기록하기 전에
     * PaymentResponseRedactor로 가리거나 portone:payment 명령을 쓴다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return array<string, mixed> 디코딩한 PortOne 응답 본문
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function paymentResponse(string $paymentId): array
    {
        return $this->client()->payment->getPayment($paymentId)->data;
    }

    /**
     * 만료 확인용으로 결제 1건을 조회한다. PortOne이 결제를 모르면 found가 false이다.
     *
     * PAYMENT_NOT_FOUND는 T-003·T-021에서 결제창을 연 적 없는 결제에 대해 관측된 응답이다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return GatewayPayment PortOne 결제 상태와 실결제액
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PAYMENT_NOT_FOUND가 아닌 PortOne API 호출 실패 (원문 그대로 전달)
     */
    public function lookupForExpiry(string $paymentId): GatewayPayment
    {
        try {
            return $this->lookup($paymentId);
        } catch (PortOneException $exception) {
            if ($exception->errorType !== 'PAYMENT_NOT_FOUND') {
                throw $exception;
            }

            return new GatewayPayment('PAYMENT_NOT_FOUND', null, found: false);
        }
    }

    /**
     * PortOne 결제를 취소(환불)한다. 금액 생략 시 전액 취소이다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $reason  PortOne에 전달할 환불 사유
     * @param  int|null  $amount  환불 금액(원, null이면 전액)
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     */
    public function cancel(string $paymentId, string $reason, ?int $amount): void
    {
        $this->client()->payment->cancelPayment(
            paymentId: $paymentId,
            amount: $amount,
            reason: $reason,
        );
    }

    /**
     * API Secret이 설정되어 있는지 확인한다. 요청은 보내지 않는다.
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     */
    public function assertConfigured(): void
    {
        $this->apiSecret();
    }

    /**
     * PortOne에서 빌링키 정보를 조회한다.
     *
     * @param  string  $billingKey  평문 빌링키
     * @return GatewayBillingKey 빌링키 상태, 발급 고객 ID, Issue ID
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function billingKeyInfo(#[\SensitiveParameter] string $billingKey): GatewayBillingKey
    {
        return $this->gatewayBillingKey($this->client()->payment->billingKey->getBillingKeyInfo($billingKey));
    }

    /**
     * PortOne 빌링키 정보 응답을 조회 결과로 바꾼다. T-045 관측: status, customer.id, issueId.
     *
     * @param  BillingKeyInfo  $info  getBillingKeyInfo 응답
     * @return GatewayBillingKey 빌링키 상태, 발급 고객 ID, Issue ID
     */
    protected function gatewayBillingKey(BillingKeyInfo $info): GatewayBillingKey
    {
        $customer = $info->data['customer'] ?? null;
        $customerId = is_array($customer) ? ($customer['id'] ?? null) : null;
        $issueId = $info->data['issueId'] ?? null;

        return new GatewayBillingKey(
            $info->variant,
            is_string($customerId) ? $customerId : null,
            is_string($issueId) ? $issueId : null,
        );
    }

    /**
     * PortOne에서 빌링키를 삭제한다.
     *
     * DeleteBillingKeyResponse는 OperationResponse::$data를 채우지 않으므로 응답을 읽지 않는다(T-045).
     *
     * @param  string  $billingKey  평문 빌링키
     * @param  string  $reason  삭제 사유
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function deleteBillingKey(#[\SensitiveParameter] string $billingKey, string $reason): void
    {
        $this->client()->payment->billingKey->deleteBillingKey(billingKey: $billingKey, reason: $reason);
    }

    /**
     * 빌링키로 원화 결제를 요청한다. T-045 관측: 빌링키, 결제 ID, 주문명, amount.total, currency만으로 결제된다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  string  $billingKey  평문 빌링키
     * @param  string  $orderName  주문명
     * @param  int  $amount  결제 금액(원)
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     * @throws PortOneException PortOne API 호출이 실패했을 때 (원문 그대로 전달)
     */
    public function payWithBillingKey(string $paymentId, #[\SensitiveParameter] string $billingKey, string $orderName, int $amount): void
    {
        $this->client()->payment->payWithBillingKey(
            paymentId: $paymentId,
            billingKey: $billingKey,
            orderName: $orderName,
            amount: ['total' => $amount],
            currency: self::CURRENCY,
        );
    }

    /**
     * 설정된 API Secret과 기본 주소로 PortOne 클라이언트를 만든다.
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     */
    private function client(): PortOneClient
    {
        $apiSecret = $this->apiSecret();
        $baseUrl = config('portone.base_url');
        $baseUrl = is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://api.portone.io';

        return new PortOneClient($apiSecret, new PortOneRequestExecutor($apiSecret, $baseUrl));
    }

    /**
     * 설정된 API Secret을 반환한다.
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     */
    private function apiSecret(): string
    {
        $apiSecret = config('portone.api_secret');

        if (! is_string($apiSecret) || $apiSecret === '') {
            throw new ConfigurationException('The PortOne API secret is not configured.');
        }

        return $apiSecret;
    }
}
