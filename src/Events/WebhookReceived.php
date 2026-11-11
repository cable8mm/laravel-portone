<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\PortonePhp\Webhook\Webhook;

/**
 * 서명 검증에 성공한 PortOne 웹훅을 알리는 이벤트.
 *
 * webhook-id당 정확히 1건, 그 웹훅을 처음 처리해 커밋한 뒤 발송된다. 이미 처리된 webhook-id가 다시 오면 발송되지 않는다.
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
