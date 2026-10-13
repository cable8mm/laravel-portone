<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Http\Controllers;

use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\PortOneWebhookVerifier;
use Cable8mm\PortonePhp\Exception\WebhookVerificationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 포트원 웹훅 수신 컨트롤러.
 *
 * 원문(raw body)으로 서명을 검증한 뒤 WebhookReceived 이벤트를 발송한다.
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

        return response('', 200);
    }
}
