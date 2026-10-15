<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\PortonePhp\Webhook\WebhookTransaction;

/**
 * 서명 검증에 성공한 거래(Transaction.*) 웹훅을 종류별로 알리는 이벤트의 공통 부분.
 *
 * WebhookReceived 다음에 발송된다. 취소 웹훅(PaymentCancelled·PaymentPartiallyCancelled)이면 발송 전에
 * 결제 레코드의 취소 상태를 PortOne과 맞춰 두므로, 리스너는 최신 레코드를 읽는다.
 */
abstract class PaymentWebhookEvent
{
    /**
     * @param  string  $paymentId  웹훅 data의 PortOne 결제 식별자
     * @param  WebhookTransaction  $webhook  검증된 포트원 거래 웹훅 객체
     * @param  string  $webhookId  webhook-id 헤더 값
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly WebhookTransaction $webhook,
        public readonly string $webhookId,
    ) {}
}
