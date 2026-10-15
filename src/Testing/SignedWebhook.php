<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Testing;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\PortonePhp\Webhook\WebhookSigner;

/**
 * 테스트·로컬 재전송용 웹훅 본문을 만들고 설정된 웹훅 Secret으로 서명한다.
 *
 * 서명은 cable8mm/portone-php의 WebhookSigner로 만들므로 패키지 웹훅 경로의 검증을 그대로 통과한다.
 * InteractsWithPortOneWebhooks와 portone:webhook-replay가 함께 쓴다.
 *
 * @internal
 */
final class SignedWebhook
{
    /**
     * PortOne 거래 웹훅 형식의 본문을 만든다.
     *
     * data에는 결제 ID와 portone.store_id가 들어가며, 추가 데이터가 같은 키를 덮어쓴다.
     *
     * @param  string  $type  웹훅 종류(예: Transaction.Paid)
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  array<string, mixed>  $data  data에 더할 값(예: transactionId, cancellationId)
     * @return string JSON 본문
     */
    public static function body(string $type, string $paymentId, array $data = []): string
    {
        $storeId = config('portone.store_id');

        return json_encode([
            'type' => $type,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => array_merge([
                'paymentId' => $paymentId,
                'storeId' => is_string($storeId) ? $storeId : '',
            ], $data),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * 본문의 webhook-id, webhook-signature, webhook-timestamp 헤더를 만든다.
     *
     * @param  string  $body  서명할 본문
     * @return array<string, string> 헤더 이름과 값
     *
     * @throws ConfigurationException 웹훅 Secret이 설정되지 않았을 때
     */
    public static function headers(string $body): array
    {
        $secret = config('portone.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new ConfigurationException('The PortOne webhook secret is not configured.');
        }

        return (new WebhookSigner($secret))->headers($body);
    }
}
