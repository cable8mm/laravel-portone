<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Http\Controllers;

use Cable8mm\LaravelPortone\Events\PaymentCancelled;
use Cable8mm\LaravelPortone\Events\PaymentFailed;
use Cable8mm\LaravelPortone\Events\PaymentPaid;
use Cable8mm\LaravelPortone\Events\PaymentPartiallyCancelled;
use Cable8mm\LaravelPortone\Events\PaymentWebhookEvent;
use Cable8mm\LaravelPortone\Events\WebhookFailed;
use Cable8mm\LaravelPortone\Events\WebhookReceived;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\PaymentOperations;
use Cable8mm\LaravelPortone\PortOneWebhookVerifier;
use Cable8mm\LaravelPortone\WebhookLog;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Cable8mm\PortonePhp\Exception\WebhookVerificationException;
use Cable8mm\PortonePhp\Webhook\UnrecognizedWebhook;
use Cable8mm\PortonePhp\Webhook\Webhook;
use Cable8mm\PortonePhp\Webhook\WebhookBillingKey;
use Cable8mm\PortonePhp\Webhook\WebhookTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 포트원 웹훅 수신 컨트롤러.
 *
 * 원문(raw body)으로 서명을 검증한 뒤, 어떤 효과보다 먼저 webhook-id의 웹훅 기록을 남기고(WebhookLog),
 * 처음 처리하는 webhook-id만 처리한다. 취소 웹훅(Transaction.Cancelled·Transaction.PartialCancelled)이면 PortOne을
 * 조회해 로컬 결제 레코드의 취소 상태를 맞추고, 같은 트랜잭션에서 기록을 처리 완료로 표시한다. 커밋 뒤
 * WebhookReceived를 발송하고, 거래 웹훅이면 종류별 결제 이벤트(PaymentPaid·PaymentCancelled·PaymentPartiallyCancelled·
 * PaymentFailed)를 이어서 발송한다.
 * 이미 처리된 webhook-id는 아무것도 하지 않고 200을 반환한다. 설정 누락이나 PortOne 예외로 처리가 실패하면 효과를
 * 롤백하고 기록을 실패로 표시한 뒤 WebhookFailed만 발송하고 예외를 전파해 PortOne이 다시 보내게 한다.
 * 성공 200(빈 응답), 검증 실패 400, Secret 미설정 500을 반환한다.
 */
final class WebhookController
{
    /**
     * 웹훅 요청을 검증하고 이벤트로 변환한다.
     *
     * @param  Request  $request  포트원 웹훅 HTTP 요청
     * @param  PortOneWebhookVerifier  $verifier  서명 검증기
     * @param  PaymentOperations  $operations  취소 웹훅의 결제 레코드 동기화
     * @param  WebhookLog  $log  webhook-id별 웹훅 기록
     * @return Response 빈 응답 (200 검증 성공, 400 검증 실패, 500 Secret 미설정)
     *
     * @throws ConfigurationException|PortOneException 처리가 실패했을 때(WebhookFailed 발송 뒤)
     */
    public function __invoke(Request $request, PortOneWebhookVerifier $verifier, PaymentOperations $operations, WebhookLog $log): Response
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

        $type = $this->type($webhook);

        try {
            $processed = $log->process(
                $webhookId,
                $type,
                $request->getContent(),
                fn () => $this->syncCancellation($webhook, $operations),
            );
        } catch (ConfigurationException|PortOneException $exception) {
            $log->markFailed($webhookId, $exception);
            event(new WebhookFailed($webhookId, $type, $exception));

            throw $exception;
        }

        if (! $processed) {
            return response('', 200);
        }

        event(new WebhookReceived($webhook, $webhookId));

        $paymentEvent = $this->paymentEvent($webhook, $webhookId);

        if ($paymentEvent !== null) {
            event($paymentEvent);
        }

        return response('', 200);
    }

    /**
     * 웹훅 종류를 반환한다. 알 수 없는 웹훅은 본문의 type, 그것도 없으면 빈 문자열이다.
     *
     * @param  Webhook  $webhook  검증된 포트원 웹훅 객체
     */
    private function type(Webhook $webhook): string
    {
        if ($webhook instanceof WebhookTransaction || $webhook instanceof WebhookBillingKey) {
            return $webhook->type;
        }

        $type = $webhook instanceof UnrecognizedWebhook ? ($webhook->body['type'] ?? null) : null;

        return is_string($type) ? $type : '';
    }

    /**
     * 취소 웹훅이면 결제 레코드의 취소 상태를 PortOne과 맞춘다. 결제 레코드가 없으면 건너뛴다.
     *
     * @param  Webhook  $webhook  검증된 포트원 웹훅 객체
     * @param  PaymentOperations  $operations  결제 레코드 동기화
     */
    private function syncCancellation(Webhook $webhook, PaymentOperations $operations): void
    {
        if (! $webhook instanceof WebhookTransaction
            || ! in_array($webhook->type, ['Transaction.Cancelled', 'Transaction.PartialCancelled'], true)) {
            return;
        }

        try {
            $operations->syncByPaymentId($webhook->data->paymentId);
        } catch (PaymentNotFoundException) {
            // 이 패키지로 만들지 않은 결제다. 동기화 없이 이벤트만 보낸다.
        }
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
