<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Testing;

use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 서명된 PortOne 웹훅을 패키지 웹훅 경로(portone.webhook)로 보내는 테스트 도우미.
 *
 * Laravel 테스트 케이스에서 use 한다. 설정된 portone.webhook_secret으로 서명하므로 실제 웹훅과 같이
 * 검증·이벤트 발송·취소 동기화를 거친다.
 *
 *     $this->postPortOneWebhook('Transaction.Cancelled', $paymentId)->assertOk();
 */
trait InteractsWithPortOneWebhooks
{
    /**
     * 서명된 웹훅을 패키지 웹훅 경로로 보낸다.
     *
     * @param  string  $type  웹훅 종류(예: Transaction.Paid, Transaction.Cancelled)
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  array<string, mixed>  $data  data에 더할 값(예: transactionId, cancellationId)
     * @return TestResponse<Response> 웹훅 경로의 응답
     *
     * @throws ConfigurationException 웹훅 Secret이 설정되지 않았을 때
     */
    protected function postPortOneWebhook(string $type, string $paymentId, array $data = []): TestResponse
    {
        $body = SignedWebhook::body($type, $paymentId, $data);
        $server = [];

        foreach (SignedWebhook::headers($body) as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', route('portone.webhook', absolute: false), [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
    }
}
