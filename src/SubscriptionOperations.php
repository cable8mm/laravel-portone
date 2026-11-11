<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Events\SubscriptionEvent;
use Cable8mm\LaravelPortone\Events\SubscriptionRenewalFailed;
use Cable8mm\LaravelPortone\Events\SubscriptionStarted;
use Cable8mm\LaravelPortone\Exceptions\AmountMismatchException;
use Cable8mm\LaravelPortone\Exceptions\ConfigurationException;
use Cable8mm\LaravelPortone\Exceptions\DuplicatePaymentException;
use Cable8mm\LaravelPortone\Exceptions\InvalidBillingKeyException;
use Cable8mm\LaravelPortone\Exceptions\InvalidSubscriptionStatusException;
use Cable8mm\LaravelPortone\Exceptions\PaymentNotPaidException;
use Cable8mm\LaravelPortone\Models\BillingKey;
use Cable8mm\LaravelPortone\Models\Subscription;
use Cable8mm\PortonePhp\Exception\PortOneException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 구독 시작·갱신·재시도·만료·해지 흐름을 조율하고 구독 기록을 저장하는 서비스.
 *
 * 결제는 PaymentOperations의 빌링키 결제로, 상태 판단은 SubscriptionLifecycle로, 이벤트는 EventDispatcher로 넘긴다.
 * 구독 기록의 배타 접근은 애플리케이션 캐시의 원자적 잠금이다. 갱신 결제는 결제 레코드를 커밋한 뒤 PortOne에 요청해야
 * 중단 뒤 다시 실행해도 그 레코드를 찾을 수 있으므로, 결제 요청 동안 데이터베이스 트랜잭션을 열어 둘 수 없기 때문이다.
 * 각 저장은 그 잠금 안에서 행 잠금을 잡고 다시 읽은 기록에 대해 한다.
 */
class SubscriptionOperations
{
    /** 잠금이 저절로 풀리기까지의 초(작업이 중단되었을 때). */
    private const LOCK_SECONDS = 300;

    /** 다른 작업이 잡은 잠금을 기다리는 최대 초. */
    private const LOCK_WAIT_SECONDS = 120;

    /**
     * @param  PaymentOperations  $payments  빌링키 결제와 결제 레코드 확정
     * @param  BillingKeyRegistry  $billingKeys  빌링키 기록 저장소
     * @param  SubscriptionLifecycle  $lifecycle  구독 상태 규칙
     * @param  EventDispatcher  $events  커밋 뒤 구독 이벤트 발송
     */
    public function __construct(
        private readonly PaymentOperations $payments,
        private readonly BillingKeyRegistry $billingKeys,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly EventDispatcher $events,
    ) {}

    /**
     * 이 모델의 구독 기록 관계(최신순)를 반환한다.
     *
     * @template TBillable of Model
     *
     * @param  TBillable  $billable  조회할 Billable 모델
     * @return MorphMany<Subscription, TBillable> 생성일 내림차순 구독 기록 관계
     */
    public function subscriptions(Model $billable): MorphMany
    {
        return $billable->morphMany(Subscription::class, 'billable')
            ->orderByDesc('created_at');
    }

    /**
     * 첫 기간을 결제하고, PAID가 되면 ACTIVE 구독 기록을 만든다(FR-131 to FR-133).
     *
     * 첫 결제가 실패하면 구독 기록을 만들지 않고 그 결제의 예외를 그대로 던진다.
     *
     * @param  Model  $billable  구독을 소유할 Billable 모델
     * @param  string  $issueId  결제할 ISSUED 빌링키 기록의 Issue ID
     * @param  string  $name  구독 이름
     * @param  int  $amount  기간마다 결제할 금액(원)
     * @param  string  $orderName  주문명
     * @param  string  $interval  기간: <n>d, <n>m, <n>y
     * @return Subscription ACTIVE 구독 기록
     *
     * @throws \InvalidArgumentException 인자가 규칙에 맞지 않을 때(PortOne 요청 없음)
     */
    public function subscribe(Model $billable, string $issueId, string $name, int $amount, string $orderName, string $interval): Subscription
    {
        $this->lifecycle->validate($name, $amount, $orderName, $interval);

        $payment = $this->payments->chargeBillingKey($billable, $issueId, $amount, $orderName);

        if ($payment->status !== 'PAID' || $payment->paid_at === null || $payment->billing_key_id === null) {
            throw new PaymentNotPaidException('The first subscription charge did not become paid.');
        }

        $billingKeyId = $payment->billing_key_id;
        $paidAt = $payment->paid_at;

        // The billing-key row lock orders the creation with EL-075, which refuses a key that a subscription charges.
        return $this->billingKeys->withExclusiveAccess($billable, $issueId, function () use ($billable, $payment, $billingKeyId, $paidAt, $name, $amount, $orderName, $interval): Subscription {
            $subscription = Subscription::query()->create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => (string) $payment->billable_id,
                'billing_key_id' => $billingKeyId,
                'name' => $name,
                'amount' => $amount,
                'order_name' => $orderName,
                'interval' => $interval,
                ...$this->lifecycle->start($paidAt, $interval),
            ]);
            $this->payments->linkSubscription($payment, $subscription->id);
            $this->events->dispatchAfterCommit(new SubscriptionStarted($subscription));

            return $subscription;
        });
    }

    /**
     * 결제할 때가 된(ACTIVE·PAST_DUE이고 next_charge_at이 지난) 구독 기록의 키를 모든 Billable 모델에서 고른다(FR-134).
     *
     * portone:charge-subscriptions 전용이다. 기록을 바꾸지 않고 PortOne에 요청하지 않는다.
     *
     * @param  Carbon  $now  현재 시각
     * @return list<int> 구독 기록의 키
     */
    public function dueIds(Carbon $now): array
    {
        return array_values(Subscription::query()
            ->whereIn('status', ['ACTIVE', 'PAST_DUE'])
            ->where('next_charge_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * 결제할 때가 된 구독 1건을 갱신·재시도·만료하거나 끝낸다(FR-135 to FR-140).
     *
     * 실행 시점에 결제할 때가 아니면 아무것도 하지 않는다. 해지 예약되었으면 결제 없이 ENDED가 된다.
     * 그 밖에는 이 기간의 PENDING 결제 레코드를 먼저 확정하고, 모두 미결제일 때만 빌링키로 다시 결제한다.
     * 설정 누락(ConfigurationException)·PortOne 조회 실패·저장 실패는 아무것도 바꾸지 않고 전파되어 큐가 다시 실행한다.
     *
     * @param  int  $subscriptionId  구독 기록의 키
     *
     * @throws LockTimeoutException 다른 작업이 잡은 잠금이 풀리지 않을 때
     */
    public function charge(int $subscriptionId): void
    {
        $this->withExclusiveAccess($subscriptionId, function () use ($subscriptionId): void {
            $subscription = Subscription::query()->find($subscriptionId);

            if ($subscription === null || ! $this->lifecycle->isDue($subscription, now())) {
                return;
            }

            if ($subscription->cancel_at_period_end) {
                $this->write($subscriptionId, fn (): array => $this->lifecycle->end(now()));

                return;
            }

            $paid = $this->chargePeriod($subscription);
            $retryAfterDays = $this->lifecycle->retryAfterDays(config('portone.subscription_retry_after', '1,3,5'));

            $this->write($subscriptionId, fn (Subscription $locked): array => $paid
                ? $this->lifecycle->renew($locked)
                : $this->lifecycle->fail($locked, now(), $retryAfterDays));
        });
    }

    /**
     * 현재 기간이 끝날 때 끝나도록 예약한다(FR-141).
     *
     * @param  Subscription  $subscription  구독 기록
     * @return Subscription 저장 뒤의 구독 기록
     *
     * @throws InvalidSubscriptionStatusException ACTIVE·PAST_DUE가 아닐 때
     */
    public function cancel(Subscription $subscription): Subscription
    {
        return $this->withExclusiveAccess($subscription->id, fn (): Subscription => $this->write(
            $subscription->id,
            fn (Subscription $locked): ?array => $this->lifecycle->cancel($locked, now()),
        ));
    }

    /**
     * 해지 예약을 취소한다(FR-142).
     *
     * @param  Subscription  $subscription  구독 기록
     * @return Subscription 저장 뒤의 구독 기록
     *
     * @throws InvalidSubscriptionStatusException 해지 예약된 ACTIVE 구독이 아닐 때
     */
    public function resume(Subscription $subscription): Subscription
    {
        return $this->withExclusiveAccess($subscription->id, fn (): Subscription => $this->write(
            $subscription->id,
            fn (Subscription $locked): array => $this->lifecycle->resume($locked),
        ));
    }

    /**
     * 다음 결제부터 같은 Billable 모델의 다른 ISSUED 빌링키 기록으로 결제한다(FR-143).
     *
     * @param  Subscription  $subscription  구독 기록
     * @param  string  $issueId  새 빌링키 기록의 Issue ID
     * @return Subscription 저장 뒤의 구독 기록
     *
     * @throws InvalidSubscriptionStatusException ACTIVE·PAST_DUE가 아닐 때
     * @throws InvalidBillingKeyException 같은 Billable 모델의 ISSUED 기록이 아닐 때
     */
    public function changeBillingKey(Subscription $subscription, string $issueId): Subscription
    {
        return $this->withExclusiveAccess($subscription->id, function () use ($subscription, $issueId): Subscription {
            $billable = $subscription->billable()->first() ?? throw new InvalidBillingKeyException('The subscription has no Billable model.');

            return $this->billingKeys->withExclusiveAccess($billable, $issueId, function (?BillingKey $record) use ($subscription): Subscription {
                if ($record?->status !== 'ISSUED') {
                    throw new InvalidBillingKeyException('The billing key is not an issued billing key of this Billable model.');
                }

                return $this->write($subscription->id, fn (Subscription $locked): array => $this->lifecycle->changeBillingKey($locked, $record->id));
            });
        });
    }

    /**
     * 이번 기간을 결제한다(FR-137). PAID가 되면 true, 결제가 실패했거나 PAID가 되지 않았으면 false이다.
     *
     * 이 기간의 PENDING 결제 레코드를 먼저 확정하고, PortOne이 모두 미결제라고 할 때만 빌링키로 새로 결제한다.
     * 새 결제 레코드는 PortOne 요청 전에 이 구독에 연결되어 커밋된다.
     *
     * @param  Subscription  $subscription  결제할 때가 된 구독 기록
     *
     * @throws ConfigurationException API Secret이 설정되지 않았을 때(아무것도 바꾸지 않는다)
     */
    private function chargePeriod(Subscription $subscription): bool
    {
        try {
            if ($this->payments->completeSubscriptionPeriod($subscription->id, $subscription->current_period_end) !== null) {
                return true;
            }
        } catch (AmountMismatchException|DuplicatePaymentException) {
            return false;
        }

        try {
            $billable = $subscription->billable()->first() ?? throw new InvalidBillingKeyException('The subscription has no Billable model.');
            $issueId = BillingKey::query()->whereKey($subscription->billing_key_id)->value('issue_id');

            $payment = $this->payments->chargeBillingKey(
                $billable,
                is_string($issueId) ? $issueId : '',
                $subscription->amount,
                $subscription->order_name,
                $subscription->id,
            );
        } catch (PortOneException|InvalidBillingKeyException|PaymentNotPaidException|AmountMismatchException|DuplicatePaymentException) {
            return false;
        }

        return $payment->status === 'PAID';
    }

    /**
     * 구독 기록의 행 잠금을 잡고 다시 읽은 기록에 판단 결과를 저장한다. 이벤트는 커밋 뒤에 1번씩 발송한다.
     *
     * 판단이 null이면 아무것도 저장하지 않는다. 판단이 예외를 던지면 아무것도 바뀌지 않는다.
     *
     * @param  int  $subscriptionId  구독 기록의 키
     * @param  Closure(Subscription): (array{values: array<string, mixed>, events: list<class-string<SubscriptionEvent>>}|null)  $decide  잠근 기록에 대한 판단
     * @return Subscription 저장 뒤의 구독 기록
     */
    private function write(int $subscriptionId, Closure $decide): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $decide): Subscription {
            $subscription = Subscription::query()->whereKey($subscriptionId)->lockForUpdate()->firstOrFail();
            $outcome = $decide($subscription);

            if ($outcome === null) {
                return $subscription;
            }

            $subscription->forceFill($outcome['values'])->save();

            foreach ($outcome['events'] as $event) {
                $this->events->dispatchAfterCommit($event === SubscriptionRenewalFailed::class
                    ? new SubscriptionRenewalFailed($subscription, $subscription->failed_attempts, $subscription->next_charge_at)
                    : new $event($subscription));
            }

            return $subscription;
        });
    }

    /**
     * 구독 기록의 배타 접근 안에서 연산을 실행한다. 같은 구독의 다른 연산은 끝날 때까지 기다린다.
     *
     * @template TResult
     *
     * @param  int  $subscriptionId  구독 기록의 키
     * @param  Closure(): TResult  $operation  배타 접근 안에서 실행할 연산
     * @return TResult 연산이 반환한 값
     *
     * @throws LockTimeoutException 기다려도 잠금을 얻지 못했을 때
     */
    private function withExclusiveAccess(int $subscriptionId, Closure $operation): mixed
    {
        return Cache::lock('portone-subscription:'.$subscriptionId, self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $operation);
    }
}
