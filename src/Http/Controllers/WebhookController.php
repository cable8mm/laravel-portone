<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Http\Controllers;

use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\PaymentFailed;
use Cable8mm\LaravelPortone\Events\PaymentPaid;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyCancelled;
use Cable8mm\LaravelPortone\Events\PaymentWebhookEvent;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\PortOneWebhookVerifier;
use Cable8mm\PortonePhp\Exception\WebhookVerificationException;
use Cable8mm\PortonePhp\Webhook\Webhook;
use Cable8mm\PortonePhp\Webhook\WebhookTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 포트원 웹훅 수신 컨트롤러.
 *
 * 원문(raw body)으로 서명을 검증한 뒤 WebhookReceived 이벤트를 발송하고, 거래 웹훅이면
 * 종류별 결제 이벤트(PaymentPaid·PaymentCancelled·PaymentPartiallyCancelled·PaymentFailed)를 이어서 발송한다.
 * 성공 200(빈 응답), 검증 실패 400, Secret 미설정 500을 반환한다.
 */
final class WebhookController
{
    /**
     * 웹훅 요청을 검증하고 이벤트로 변환한다.
     *
     * @param  Request  $request  포트원 웹훅 HTTP 요청
     * @param  PortOneWebhookVerifier  $verifier  서명 검증기
     * @return Response 빈 응답 (200 검증 성공, 400 검증 실패, 500 Secret 미설정)
     */
    public function __invoke(Request $request, PortOneWebhookVerifier $verifier): Response
    {
        $secret = config('portone.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return response('', 500);
        }

        $webhookId = $request->headers->get('webhook-id');

        try {
            $webhook = $verifier->verify(
                $request->getContent(),
                $webhookId,
                $request->headers->get('webhook-signature'),
                $request->headers->get('webhook-timestamp'),
                $secret,
            );
        } catch (WebhookVerificationException) {
            return response('', 400);
        }

        if ($webhookId === null) {
            return response('', 400);
        }

        event(new WebhookReceived($webhook, $webhookId));

        $paymentEvent = $this->paymentEvent($webhook, $webhookId);

        if ($paymentEvent !== null) {
            event($paymentEvent);
        }

        return response('', 200);
    }

    /**
     * 거래 웹훅 종류에 맞는 결제 이벤트를 만든다. 해당하는 종류가 아니면 null이다.
     *
     * @param  Webhook  $webhook  검증된 포트원 웹훅 객체
     * @param  string  $webhookId  webhook-id 헤더 값
     */
    private function paymentEvent(Webhook $webhook, string $webhookId): ?PaymentWebhookEvent
    {
        if (! $webhook instanceof WebhookTransaction) {
            return null;
        }

        $event = match ($webhook->type) {
            'Transaction.Paid' => PaymentPaid::class,
            'Transaction.Cancelled' => PaymentCancelled::class,
            'Transaction.PartialCancelled' => PaymentPartiallyCancelled::class,
            'Transaction.Failed' => PaymentFailed::class,
            default => null,
        };

        return $event === null ? null : new $event($webhook->data->paymentId, $webhook, $webhookId);
    }
}
