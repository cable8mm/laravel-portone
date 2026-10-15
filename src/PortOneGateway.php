<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\PortonePhp\Client\PortOneClient;
use Cable8mm\PortonePhp\Exception\PortOneException;

/**
 * cable8mm/portone-php 클라이언트로 PortOne V2 API를 호출하는 게이트웨이.
 *
 * 패키지의 모든 PortOne 요청은 여기서 나간다.
 */
class PortOneGateway implements PaymentGateway
{
    /**
     * PortOne에서 결제 1건을 조회한다.
     *
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return GatewayPayment PortOne 결제 상태, 실결제액, 취소 총액
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
     * PortOne 결제 응답을 조회 결과로 바꾼다. 실결제액은 amount.total, 취소 총액은 amount.cancelled이다.
     *
     * @param  string  $status  PortOne 결제 상태(응답의 status)
     * @param  array<string, mixed>  $data  디코딩한 PortOne 응답 본문
     * @return GatewayPayment PortOne 결제 상태, 실결제액, 취소 총액
     */
    protected function gatewayPayment(string $status, array $data): GatewayPayment
    {
        $amount = $data['amount'] ?? null;
        $totalAmount = is_array($amount) && is_int($amount['total'] ?? null)
            ? $amount['total']
            : null;
        $cancelledAmount = is_array($amount) && is_int($amount['cancelled'] ?? null)
            ? $amount['cancelled']
            : null;

        return new GatewayPayment($status, $totalAmount, cancelledAmount: $cancelledAmount);
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
     * 설정된 API Secret과 기본 주소로 PortOne 클라이언트를 만든다.
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     */
    private function client(): PortOneClient
    {
        $apiSecret = config('portone.api_secret');

        if (! is_string($apiSecret) || $apiSecret === '') {
            throw new ConfigurationException('The PortOne API secret is not configured.');
        }

        $baseUrl = config('portone.base_url');
        $baseUrl = is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://api.portone.io';

        return new PortOneClient($apiSecret, new PortOneRequestExecutor($apiSecret, $baseUrl));
    }
}
