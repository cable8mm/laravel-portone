<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Throwable;

/**
 * 검증된 웹훅의 처리가 실패했음을 알리는 이벤트.
 *
 * 설정 누락(ConfigurationException)이나 PortOne 예외로 처리가 멈추면 발송된다. 웹훅 요청은 실패 응답으로 끝나므로
 * PortOne이 같은 webhook-id로 다시 보내고, 그때 다시 처리된다. 이 요청에서 다른 이벤트는 발송되지 않는다.
 */
final class WebhookFailed
{
    /**
     * @param  string  $webhookId  webhook-id 헤더 값
     * @param  string  $type  웹훅 종류
     * @param  Throwable  $exception  처리를 멈춘 예외
     */
    public function __construct(
        public readonly string $webhookId,
        public readonly string $type,
        public readonly Throwable $exception,
    ) {}
}
