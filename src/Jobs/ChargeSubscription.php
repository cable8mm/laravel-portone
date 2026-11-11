<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Jobs;

use Cable8mm\LaravelPortone\SubscriptionOperations;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * 결제할 때가 된 구독 1건을 갱신·재시도·만료하거나 끝내는 큐 잡.
 *
 * portone:charge-subscriptions가 구독마다 하나씩 큐에 넣고, 애플리케이션의 큐 워커가 실행한다.
 * 설정 누락·PortOne 조회 실패·저장 실패는 감싸지 않고 전파되어 큐가 다시 실행한다.
 */
class ChargeSubscription implements ShouldQueue
{
    /**
     * @param  int  $subscriptionId  구독 기록의 키
     */
    public function __construct(
        public readonly int $subscriptionId,
    ) {}

    /**
     * 구독 1건의 결제를 실행한다.
     */
    public function handle(SubscriptionOperations $subscriptions): void
    {
        $subscriptions->charge($this->subscriptionId);
    }
}
