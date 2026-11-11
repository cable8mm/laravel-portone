<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Events\SubscriptionCancelled;
use Cable8mm\LaravelPortone\Events\SubscriptionEnded;
use Cable8mm\LaravelPortone\Events\SubscriptionEvent;
use Cable8mm\LaravelPortone\Events\SubscriptionExpired;
use Cable8mm\LaravelPortone\Events\SubscriptionRenewalFailed;
use Cable8mm\LaravelPortone\Events\SubscriptionRenewed;
use Cable8mm\LaravelPortone\Events\SubscriptionResumed;
use Cable8mm\LaravelPortone\Exceptions\InvalidSubscriptionStatusException;
use Cable8mm\LaravelPortone\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * 구독 기록의 상태 규칙을 담은 순수 판단 모음. 저장·시계·PortOne을 다루지 않는다.
 *
 * 각 판단은 저장할 절대값과, 그 저장이 커밋된 뒤 보낼 구독 이벤트 클래스를 돌려준다.
 *
 * @phpstan-type Outcome array{values: array<string, mixed>, events: list<class-string<SubscriptionEvent>>}
 */
class SubscriptionLifecycle
{
    /**
     * 구독 시작 인자를 검사한다(ERR-026).
     *
     * @param  string  $name  구독 이름(빈 문자열 불가)
     * @param  int  $amount  기간마다 결제할 금액(원, 0보다 커야 함)
     * @param  string  $orderName  주문명(빈 문자열 불가)
     * @param  string  $interval  기간: <n>d, <n>m, <n>y (n은 1 이상)
     *
     * @throws \InvalidArgumentException 인자가 규칙에 맞지 않을 때
     */
    public function validate(string $name, int $amount, string $orderName, string $interval): void
    {
        if ($this->parseInterval($interval) === null) {
            throw new \InvalidArgumentException('The subscription interval must be <n>d, <n>m, or <n>y with n at least 1.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('The subscription amount must be greater than zero.');
        }

        if ($name === '') {
            throw new \InvalidArgumentException('The subscription name must not be empty.');
        }

        if ($orderName === '') {
            throw new \InvalidArgumentException('The subscription order name must not be empty.');
        }
    }

    /**
     * 시각에 기간을 더한다. 월·년은 말일을 넘기지 않는다(1월 31일 + 1m = 2월 말일).
     *
     * @param  Carbon  $from  시작 시각(바꾸지 않는다)
     * @param  string  $interval  검사를 통과한 기간
     */
    public function addInterval(Carbon $from, string $interval): Carbon
    {
        [$count, $unit] = $this->parseInterval($interval) ?? throw new \InvalidArgumentException('Invalid subscription interval.');
        $to = $from->copy();

        return match ($unit) {
            'd' => $to->addDays($count),
            'm' => $to->addMonthsNoOverflow($count),
            'y' => $to->addYearsNoOverflow($count),
        };
    }

    /**
     * 첫 기간 결제가 PAID가 된 구독 기록의 값(FR-131).
     *
     * @param  Carbon  $paidAt  첫 기간 결제의 paid_at
     * @param  string  $interval  검사를 통과한 기간
     * @return array<string, mixed> 저장할 값
     */
    public function start(Carbon $paidAt, string $interval): array
    {
        $periodEnd = $this->addInterval($paidAt, $interval);

        return [
            'status' => 'ACTIVE',
            'cancel_at_period_end' => false,
            'current_period_start' => $paidAt->copy(),
            'current_period_end' => $periodEnd,
            'next_charge_at' => $periodEnd->copy(),
            'failed_attempts' => 0,
            'ended_at' => null,
        ];
    }

    /**
     * 결제할 때가 된 구독인지 판단한다(FR-134, FR-135): ACTIVE 또는 PAST_DUE이고 next_charge_at이 지금보다 늦지 않다.
     *
     * @param  Subscription  $subscription  구독 기록
     * @param  Carbon  $now  현재 시각
     */
    public function isDue(Subscription $subscription, Carbon $now): bool
    {
        return in_array($subscription->status, ['ACTIVE', 'PAST_DUE'], true)
            && $subscription->next_charge_at->lessThanOrEqualTo($now);
    }

    /**
     * 해지 예약된 구독을 기간 끝에 끝낸다(FR-136).
     *
     * @param  Carbon  $now  현재 시각
     * @return Outcome
     */
    public function end(Carbon $now): array
    {
        return ['values' => ['status' => 'ENDED', 'ended_at' => $now], 'events' => [SubscriptionEnded::class]];
    }

    /**
     * 갱신 결제가 PAID가 된 구독의 다음 기간(FR-138).
     *
     * @param  Subscription  $subscription  갱신할 구독 기록
     * @return Outcome
     */
    public function renew(Subscription $subscription): array
    {
        $periodStart = $subscription->current_period_end->copy();
        $periodEnd = $this->addInterval($periodStart, $subscription->interval);

        return [
            'values' => [
                'status' => 'ACTIVE',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'next_charge_at' => $periodEnd->copy(),
                'failed_attempts' => 0,
            ],
            'events' => [SubscriptionRenewed::class],
        ];
    }

    /**
     * 갱신 결제가 실패했거나 PAID가 되지 않은 구독(FR-139).
     *
     * 실패 횟수가 재시도 대기 목록 수를 넘지 않으면 PAST_DUE와 그 위치의 대기 뒤 다시 결제, 넘으면 EXPIRED이다.
     *
     * @param  Subscription  $subscription  구독 기록
     * @param  Carbon  $now  현재 시각
     * @param  list<int>  $retryAfterDays  재시도마다 기다릴 일수(EL-058)
     * @return Outcome
     */
    public function fail(Subscription $subscription, Carbon $now, array $retryAfterDays): array
    {
        $attempts = $subscription->failed_attempts + 1;

        if ($attempts <= count($retryAfterDays)) {
            return [
                'values' => [
                    'status' => 'PAST_DUE',
                    'failed_attempts' => $attempts,
                    'next_charge_at' => $now->copy()->addDays($retryAfterDays[$attempts - 1]),
                ],
                'events' => [SubscriptionRenewalFailed::class],
            ];
        }

        return [
            'values' => ['status' => 'EXPIRED', 'failed_attempts' => $attempts, 'ended_at' => $now],
            'events' => [SubscriptionExpired::class],
        ];
    }

    /**
     * 해지 예약(FR-141). PAST_DUE는 재시도를 기다리지 않고 바로 끝낸다. 이미 해지 예약된 ACTIVE 구독은 바꾸지 않는다(null).
     *
     * @param  Subscription  $subscription  구독 기록
     * @param  Carbon  $now  현재 시각
     * @return Outcome|null 바꿀 것이 없으면 null
     *
     * @throws InvalidSubscriptionStatusException ACTIVE·PAST_DUE가 아닐 때
     */
    public function cancel(Subscription $subscription, Carbon $now): ?array
    {
        return match (true) {
            $subscription->status === 'PAST_DUE' => [
                'values' => ['cancel_at_period_end' => true, 'status' => 'ENDED', 'ended_at' => $now],
                'events' => [SubscriptionCancelled::class, SubscriptionEnded::class],
            ],
            $subscription->status === 'ACTIVE' && $subscription->cancel_at_period_end => null,
            $subscription->status === 'ACTIVE' => [
                'values' => ['cancel_at_period_end' => true],
                'events' => [SubscriptionCancelled::class],
            ],
            default => throw new InvalidSubscriptionStatusException('Only an active or past-due subscription can be cancelled.'),
        };
    }

    /**
     * 해지 예약 취소(FR-142).
     *
     * @param  Subscription  $subscription  구독 기록
     * @return Outcome
     *
     * @throws InvalidSubscriptionStatusException 해지 예약된 ACTIVE 구독이 아닐 때
     */
    public function resume(Subscription $subscription): array
    {
        if ($subscription->status !== 'ACTIVE' || ! $subscription->cancel_at_period_end) {
            throw new InvalidSubscriptionStatusException('Only an active subscription cancelled at the period end can be resumed.');
        }

        return ['values' => ['cancel_at_period_end' => false], 'events' => [SubscriptionResumed::class]];
    }

    /**
     * 결제할 빌링키 기록 변경(FR-143). 아직 결제하는 구독(ACTIVE·PAST_DUE)만 받는다.
     *
     * @param  Subscription  $subscription  구독 기록
     * @param  int  $billingKeyId  새 빌링키 기록의 키
     * @return Outcome
     *
     * @throws InvalidSubscriptionStatusException ACTIVE·PAST_DUE가 아닐 때
     */
    public function changeBillingKey(Subscription $subscription, int $billingKeyId): array
    {
        if (! in_array($subscription->status, ['ACTIVE', 'PAST_DUE'], true)) {
            throw new InvalidSubscriptionStatusException('Only an active or past-due subscription can change its billing key.');
        }

        return ['values' => ['billing_key_id' => $billingKeyId], 'events' => []];
    }

    /**
     * 재시도 대기 설정(EL-058, 쉼표로 구분한 일수)을 일수 목록으로 바꾼다. 0 이상의 정수가 아닌 항목은 무시한다.
     *
     * @param  mixed  $value  portone.subscription_retry_after 값(문자열 또는 목록)
     * @return list<int> 재시도마다 기다릴 일수
     */
    public function retryAfterDays(mixed $value): array
    {
        $entries = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
        $days = [];

        foreach ($entries as $entry) {
            $entry = is_scalar($entry) ? trim((string) $entry) : '';

            if ($entry !== '' && ctype_digit($entry)) {
                $days[] = (int) $entry;
            }
        }

        return $days;
    }

    /**
     * 기간 문자열을 [개수, 단위]로 나눈다. 형식이 틀리거나 개수가 1보다 작으면 null이다.
     *
     * @param  string  $interval  기간 문자열
     * @return array{0: int, 1: 'd'|'m'|'y'}|null
     */
    private function parseInterval(string $interval): ?array
    {
        if (preg_match('/^([0-9]{1,9})([dmy])$/', $interval, $matches) !== 1 || (int) $matches[1] < 1) {
            return null;
        }

        /** @var 'd'|'m'|'y' $unit */
        $unit = $matches[2];

        return [(int) $matches[1], $unit];
    }
}
