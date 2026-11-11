<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\Exceptions\InvalidSubscriptionStatusException;
use Cable8mm\LaravelPortone\SubscriptionOperations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * 구독 기록 Eloquent 모델. 한 Billable 모델이 소유하며, 그 모델의 빌링키 기록 하나로 기간마다 결제한다.
 *
 * 상태 전이: ACTIVE ↔ PAST_DUE, ACTIVE → ENDED, PAST_DUE → ENDED, PAST_DUE → EXPIRED.
 * 기간마다 결제 레코드 하나가 이 구독을 가리킨다(subscription_id). 패키지는 구독 기록을 지우지 않는다.
 *
 * @property int $id
 * @property string $billable_type
 * @property string $billable_id
 * @property int $billing_key_id 결제할 빌링키 기록
 * @property string $name
 * @property int $amount 기간마다 결제할 금액(원)
 * @property string $order_name
 * @property string $interval 기간: <n>d, <n>m, <n>y
 * @property string $status ACTIVE, PAST_DUE, ENDED, EXPIRED 중 하나
 * @property bool $cancel_at_period_end 현재 기간이 끝나면 끝낼지
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property Carbon $next_charge_at 다음 결제(갱신 또는 재시도) 시각
 * @property int $failed_attempts 연속으로 실패한 갱신 결제 횟수
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Subscription extends Model
{
    protected $table = 'portone_subscriptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'failed_attempts' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_charge_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * 구독을 소유한 Billable 모델.
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 구독이 결제하는 빌링키 기록.
     *
     * @return BelongsTo<BillingKey, $this>
     */
    public function billingKey(): BelongsTo
    {
        return $this->belongsTo(BillingKey::class);
    }

    /**
     * 현재 기간이 끝날 때 구독을 끝내도록 예약한다. PAST_DUE면 재시도를 기다리지 않고 바로 끝낸다.
     *
     * @return Subscription 저장 뒤의 구독 기록
     *
     * @throws InvalidSubscriptionStatusException ACTIVE·PAST_DUE가 아닐 때
     */
    public function cancel(): self
    {
        return app(SubscriptionOperations::class)->cancel($this);
    }

    /**
     * 기간이 끝나기 전에 해지 예약(cancel())을 취소한다.
     *
     * @return Subscription 저장 뒤의 구독 기록
     *
     * @throws InvalidSubscriptionStatusException 해지 예약된 ACTIVE 구독이 아닐 때
     */
    public function resume(): self
    {
        return app(SubscriptionOperations::class)->resume($this);
    }

    /**
     * 다음 결제부터 같은 Billable 모델의 다른 ISSUED 빌링키 기록으로 결제한다.
     *
     * @param  string  $issueId  새로 결제할 빌링키 기록의 Issue ID
     * @return Subscription 저장 뒤의 구독 기록
     *
     * @throws InvalidSubscriptionStatusException ACTIVE·PAST_DUE가 아닐 때
     * @throws InvalidBillingKeyException 같은 Billable 모델의 ISSUED 기록이 아닐 때
     */
    public function changeBillingKey(string $issueId): self
    {
        return app(SubscriptionOperations::class)->changeBillingKey($this, $issueId);
    }
}
