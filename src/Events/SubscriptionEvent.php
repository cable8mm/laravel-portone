<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\Subscription;

/**
 * 구독 기록의 변경 1건을 알리는 이벤트의 공통 부분.
 *
 * 커밋된 변경 1건당 정확히 1번, 커밋 뒤에 발송된다. 저장이 롤백되면 발송되지 않는다.
 */
abstract class SubscriptionEvent
{
    /**
     * @param  Subscription  $subscription  저장 뒤의 구독 기록
     */
    public function __construct(
        public readonly Subscription $subscription,
    ) {}
}
