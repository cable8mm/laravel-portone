<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Events;

use Cable8mm\LaravelPortone\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * 갱신 결제가 실패해 구독이 PAST_DUE가 되었다. nextChargeAt에 다시 결제한다.
 */
final class SubscriptionRenewalFailed extends SubscriptionEvent
{
    /**
     * @param  Subscription  $subscription  저장 뒤의 구독 기록
     * @param  int  $attempt  연속으로 실패한 갱신 결제 횟수(failed_attempts)
     * @param  Carbon  $nextChargeAt  다시 결제할 시각(next_charge_at)
     */
    public function __construct(
        Subscription $subscription,
        public readonly int $attempt,
        public readonly Carbon $nextChargeAt,
    ) {
        parent::__construct($subscription);
    }
}
