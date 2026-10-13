<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\PortonePhp\Client\PortOneClient;

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
     * @return GatewayPayment PortOne 결제 상태와 실결제액
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때
     */
    public function lookup(string $paymentId): GatewayPayment
    {
        $apiSecret = config('portone.api_secret');

        if (! is_string($apiSecret) || $apiSecret === '') {
            throw new ConfigurationException('The PortOne API secret is not configured.');
        }

        $baseUrl = config('portone.base_url');
        $baseUrl = is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://api.portone.io';
        $client = new PortOneClient($apiSecret, new PortOneRequestExecutor($apiSecret, $baseUrl));
        $payment = $client->payment->getPayment($paymentId);
        $amount = $payment->data['amount'] ?? null;
        $totalAmount = is_array($amount) && is_int($amount['total'] ?? null)
            ? $amount['total']
            : null;

        return new GatewayPayment($payment->variant, $totalAmount);
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
        $apiSecret = config('portone.api_secret');

        if (! is_string($apiSecret) || $apiSecret === '') {
            throw new ConfigurationException('The PortOne API secret is not configured.');
        }

        $baseUrl = config('portone.base_url');
        $baseUrl = is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://api.portone.io';
        $client = new PortOneClient($apiSecret, new PortOneRequestExecutor($apiSecret, $baseUrl));
        $client->payment->cancelPayment(
            paymentId: $paymentId,
            amount: $amount,
            reason: $reason,
        );
    }
}
