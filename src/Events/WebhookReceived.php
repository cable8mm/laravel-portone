<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\PortonePhp\Webhook\Webhook;

/**
 * 서명 검증에 성공한 PortOne 웹훅을 알리는 이벤트.
 *
 * 검증된 요청당 정확히 1건 발송된다. 고객·결제 레코드를 읽거나 쓰지 않는다.
 */
final class WebhookReceived
{
    /**
     * @param  Webhook  $webhook  검증된 포트원 웹훅 객체
     * @param  string  $webhookId  webhook-id 헤더 값
     */
    public function __construct(
        public readonly Webhook $webhook,
        public readonly string $webhookId,
    ) {}
}
