<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\Webhook;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 웹훅 기록 저장소. webhook-id마다 웹훅 기록 1건을 두어 같은 웹훅이 두 번 효과를 내지 않게 한다.
 *
 * 기록은 어떤 효과보다 먼저 만든다. 효과는 그 webhook-id의 배타 접근(행 잠금) 안에서 실행하고, 성공하면 같은
 * 트랜잭션에서 처리 완료로 표시한다. 같은 webhook-id가 동시에 오면 나중 요청은 앞 요청이 끝날 때까지 기다린 뒤
 * 처리 완료를 보고 아무것도 하지 않는다. 처리 실패 표시는 효과가 롤백된 뒤 별도 쓰기로 한다.
 */
class WebhookLog
{
    /**
     * 웹훅 기록을 만들거나 받은 횟수를 늘린 뒤, 아직 처리되지 않았으면 효과를 실행하고 처리 완료로 표시한다.
     *
     * 효과가 예외를 던지면 효과와 처리 완료 표시가 함께 롤백되고 예외가 그대로 전파된다.
     *
     * @param  string  $webhookId  webhook-id 헤더 값
     * @param  string  $type  웹훅 종류
     * @param  string  $body  받은 그대로의 요청 본문
     * @param  Closure(): void  $effect  처음 처리할 때 실행할 효과
     * @return bool 이번 요청에서 처리했으면 true, 이미 처리된 webhook-id면 false
     */
    public function process(string $webhookId, string $type, string $body, Closure $effect): bool
    {
        Webhook::query()->insertOrIgnore([
            'webhook_id' => $webhookId,
            'type' => $type,
            'body' => $body,
            'attempts' => 0,
            'received_at' => now(),
        ]);
        Webhook::query()->where('webhook_id', $webhookId)->increment('attempts');

        return DB::transaction(function () use ($webhookId, $effect): bool {
            $record = Webhook::query()
                ->where('webhook_id', $webhookId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->processed_at !== null) {
                return false;
            }

            $effect();

            $record->forceFill([
                'processed_at' => now(),
                'failed_at' => null,
                'failure' => null,
            ])->save();

            return true;
        });
    }

    /**
     * 처리에 실패한 웹훅 기록에 실패 시각과 원인을 남긴다. 다음 전달 때 다시 처리된다.
     *
     * @param  string  $webhookId  webhook-id 헤더 값
     * @param  Throwable  $exception  처리를 멈춘 예외
     */
    public function markFailed(string $webhookId, Throwable $exception): void
    {
        Webhook::query()
            ->where('webhook_id', $webhookId)
            ->whereNull('processed_at')
            ->update([
                'failed_at' => now(),
                'failure' => $exception::class.': '.$exception->getMessage(),
            ]);
    }
}
