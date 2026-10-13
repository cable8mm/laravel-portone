<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\PortonePhp\Webhook\Webhook;
use Cable8mm\PortonePhp\Webhook\WebhookVerifier;

/**
 * cable8mm/portone-php 검증기로 웹훅 서명을 확인하는 래퍼.
 *
 * 원문(raw body)을 JSON 디코딩 전에 검증해야 한다.
 */
final class PortOneWebhookVerifier
{
    /**
     * 웹훅 서명을 검증하고 웹훅 객체를 반환한다.
     *
     * @param  string  $rawBody  변조되지 않은 요청 원문
     * @param  string|null  $webhookId  webhook-id 헤더 값
     * @param  string|null  $webhookSignature  webhook-signature 헤더 값
     * @param  string|null  $webhookTimestamp  webhook-timestamp 헤더 값
     * @param  string  $secret  웹훅 Secret
     * @return Webhook 검증된 포트원 웹훅 객체
     */
    public function verify(
        string $rawBody,
        ?string $webhookId,
        ?string $webhookSignature,
        ?string $webhookTimestamp,
        string $secret,
    ): Webhook {
        return (new WebhookVerifier($secret))->verify(
            $rawBody,
            $webhookId,
            $webhookSignature,
            $webhookTimestamp,
        );
    }
}
