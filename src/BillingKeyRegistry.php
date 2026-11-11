<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\BillingKey;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * 빌링키 기록 저장소.
 *
 * Issue ID를 만들고, 빌링키를 암호화해 저장하며, 평문 빌링키를 꺼내는 유일한 곳이다. 꺼낸 평문은
 * PaymentOperations가 PortOne 게이트웨이에 넘길 때만 쓴다. 상태 판단은 하지 않는다.
 */
class BillingKeyRegistry
{
    /**
     * 이 모델의 빌링키 기록 관계(최신순)를 반환한다.
     *
     * @template TBillable of Model
     *
     * @param  TBillable  $billable  조회할 Billable 모델
     * @return MorphMany<BillingKey, TBillable> 생성일 내림차순 빌링키 기록 관계
     */
    public function billingKeys(Model $billable): MorphMany
    {
        return $billable->morphMany(BillingKey::class, 'billable')
            ->orderByDesc('created_at');
    }

    /**
     * PENDING 빌링키 기록을 새로 만든다. issue_id가 겹치면 다시 만든다.
     *
     * Issue ID는 'bk'와 32자리 16진수이다. 결제 ID(UUID, 하이픈 포함)와 형식이 달라 어떤 결제 ID와도 같을 수 없다.
     *
     * @param  Model  $billable  빌링키를 소유할 Billable 모델
     * @param  string  $customerId  PortOne 고객 ID
     * @param  string  $issueName  발급 이름
     * @return BillingKey 생성된 PENDING 빌링키 기록
     */
    public function createPending(Model $billable, string $customerId, string $issueName): BillingKey
    {
        $billableKey = $billable->getKey();

        if (! is_string($billableKey) && ! is_int($billableKey)) {
            throw new \InvalidArgumentException('A Billable model must have a string or integer key.');
        }

        while (true) {
            $issueId = 'bk'.bin2hex(random_bytes(16));

            try {
                return BillingKey::query()->create([
                    'issue_id' => $issueId,
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => (string) $billableKey,
                    'customer_id' => $customerId,
                    'issue_name' => $issueName,
                    'billing_key' => null,
                    'status' => 'PENDING',
                ]);
            } catch (QueryException $exception) {
                if (! BillingKey::query()->where('issue_id', $issueId)->exists()) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Billable 범위에서 Issue ID의 기록을 잠그고 콜백을 실행한다. 기록이 없으면 콜백은 null을 받는다.
     *
     * @template TResult
     *
     * @param  Model  $billable  소유 Billable 모델
     * @param  string  $issueId  Issue ID
     * @param  Closure(BillingKey|null): TResult  $operation  잠금 안에서 실행할 연산
     * @return TResult 콜백이 반환한 값
     */
    public function withExclusiveAccess(Model $billable, string $issueId, Closure $operation): mixed
    {
        return DB::transaction(fn (): mixed => $operation(
            $this->billingKeys($billable)->where('issue_id', $issueId)->lockForUpdate()->first(),
        ));
    }

    /**
     * Issue ID만으로 기록을 잠그고 콜백을 실행한다. 기록이 없으면 콜백은 null을 받는다.
     *
     * 소유 Billable을 모르는 BillingKey 웹훅 처리 전용이다. Issue ID는 PortOne 빌링키 조회에서 얻는다.
     *
     * @template TResult
     *
     * @param  string  $issueId  Issue ID
     * @param  Closure(BillingKey|null): TResult  $operation  잠금 안에서 실행할 연산
     * @return TResult 콜백이 반환한 값
     */
    public function withExclusiveAccessByIssueId(string $issueId, Closure $operation): mixed
    {
        return DB::transaction(fn (): mixed => $operation(
            BillingKey::query()->where('issue_id', $issueId)->lockForUpdate()->first(),
        ));
    }

    /**
     * 기록에 저장된 평문 빌링키를 반환한다. 발급 전이면 null이다.
     *
     * @param  BillingKey  $record  빌링키 기록
     */
    public function plainKey(BillingKey $record): ?string
    {
        return $record->billing_key === null ? null : Crypt::decryptString($record->billing_key);
    }

    /**
     * 기록에 저장된 빌링키가 주어진 빌링키와 같은지 확인한다.
     *
     * @param  BillingKey  $record  빌링키 기록
     * @param  string  $billingKey  비교할 평문 빌링키
     */
    public function holds(BillingKey $record, #[\SensitiveParameter] string $billingKey): bool
    {
        $stored = $this->plainKey($record);

        return $stored !== null && hash_equals($stored, $billingKey);
    }

    /**
     * 빌링키를 암호화해 저장하고 ISSUED로 바꾼다.
     *
     * @param  BillingKey  $record  잠금을 잡은 PENDING 또는 FAILED 기록
     * @param  string  $billingKey  PortOne이 발급을 확인해 준 평문 빌링키
     * @param  Carbon  $issuedAt  issued_at에 기록할 시각
     */
    public function markIssued(BillingKey $record, #[\SensitiveParameter] string $billingKey, Carbon $issuedAt): BillingKey
    {
        return $this->update($record, [
            'billing_key' => Crypt::encryptString($billingKey),
            'status' => 'ISSUED',
            'issued_at' => $issuedAt,
        ]);
    }

    /**
     * FAILED로 바꾼다. 저장된 빌링키는 그대로 둔다.
     *
     * @param  BillingKey  $record  잠금을 잡은 PENDING 또는 FAILED 기록
     */
    public function markFailed(BillingKey $record): BillingKey
    {
        return $this->update($record, ['status' => 'FAILED']);
    }

    /**
     * DELETED로 바꾼다.
     *
     * @param  BillingKey  $record  잠금을 잡은 ISSUED 기록
     * @param  Carbon  $deletedAt  deleted_at에 기록할 시각
     */
    public function markDeleted(BillingKey $record, Carbon $deletedAt): BillingKey
    {
        return $this->update($record, ['status' => 'DELETED', 'deleted_at' => $deletedAt]);
    }

    /**
     * @param  BillingKey  $record  갱신할 기록
     * @param  array<string, mixed>  $values  저장할 절대값
     */
    private function update(BillingKey $record, array $values): BillingKey
    {
        $record->forceFill($values)->save();

        return $record;
    }
}
