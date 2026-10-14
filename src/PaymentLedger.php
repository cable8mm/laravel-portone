<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Exceptions\PaymentNotFoundException;
use Cable8mm\LaravelPortone\Models\Payment;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 결제 레코드의 배타 접근과 저장을 전담하는 저장소.
 *
 * 결제 상태 계산은 하지 않으며, PaymentLifecycle이 계산한 값을 그대로 쓴다.
 */
class PaymentLedger
{
    /**
     * 이 모델의 결제 목록 관계(최신순)를 반환한다.
     *
     * @template TBillable of Model
     *
     * @param  TBillable  $billable  조회할 Billable 모델
     * @return MorphMany<Payment, TBillable> 생성일 내림차순 결제 관계
     */
    public function payments(Model $billable): MorphMany
    {
        return $billable->morphMany(Payment::class, 'billable')
            ->orderByDesc('created_at');
    }

    /**
     * Billable 범위 안에서 결제 1건을 찾는다.
     *
     * @param  Model  $billable  소유 Billable 모델
     * @param  string  $paymentId  PortOne 결제 식별자
     * @return Payment|null 결제 레코드, 없으면 null
     */
    public function find(Model $billable, string $paymentId): ?Payment
    {
        return $this->payments($billable)
            ->where('payment_id', $paymentId)
            ->first();
    }

    /**
     * 레코드를 잠그고 콜백을 실행한다. 범위를 벗어나면 예외가 발생한다.
     *
     * @param  Model  $billable  소유 Billable 모델
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  Closure(Payment): Payment  $operation  잠금 안에서 실행할 연산
     * @return Payment 콜백이 반환한 결제 레코드
     *
     * @throws PaymentNotFoundException 내 범위에 해당 결제가 없을 때
     */
    public function withExclusiveAccess(Model $billable, string $paymentId, Closure $operation): Payment
    {
        return DB::transaction(function () use ($billable, $paymentId, $operation): Payment {
            $payment = $this->payments($billable)
                ->where('payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                throw new PaymentNotFoundException('The payment does not belong to this Billable model.');
            }

            return $operation($payment);
        });
    }

    /**
     * Billable 참조(타입·키)로 범위를 정해 레코드를 잠그고 콜백을 실행한다.
     *
     * 만료 잡 전용이다. 레코드가 없으면(이미 삭제됐거나 범위 밖) 아무것도 하지 않는다.
     *
     * @param  string  $billableType  소유 Billable 모델의 morph 타입
     * @param  string  $billableId  소유 Billable 모델의 키
     * @param  string  $paymentId  PortOne 결제 식별자
     * @param  Closure(Payment): void  $operation  잠금 안에서 실행할 연산
     */
    public function withExclusiveAccessByReference(string $billableType, string $billableId, string $paymentId, Closure $operation): void
    {
        DB::transaction(function () use ($billableType, $billableId, $paymentId, $operation): void {
            $payment = Payment::query()
                ->where('billable_type', $billableType)
                ->where('billable_id', $billableId)
                ->where('payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if ($payment !== null) {
                $operation($payment);
            }
        });
    }

    /**
     * 기준 시각보다 먼저 만들어진 PENDING 결제를 모든 Billable에 걸쳐 고른다.
     *
     * 만료 명령 전용인 유일한 범위 밖 조회이며, 각 레코드의 Billable 참조와 결제 ID만 돌려준다.
     *
     * @param  Carbon  $createdBefore  이 시각보다 먼저 만들어진 레코드만 고른다
     * @return Collection<int, Payment> billable_type·billable_id·payment_id만 채운 결제 레코드
     */
    public function stalePending(Carbon $createdBefore): Collection
    {
        return Payment::query()
            ->where('status', 'PENDING')
            ->where('created_at', '<', $createdBefore)
            ->orderBy('id')
            ->get(['billable_type', 'billable_id', 'payment_id']);
    }

    /**
     * 미리 계산된 결과 값을 레코드에 그대로 쓴다. 증가분이 아닌 절대값이다.
     *
     * @param  Payment  $payment  갱신할 결제 레코드
     * @param  array{status: string, paid_at: Carbon}|array{status: string, cancelled_amount: int}|array{status: string}  $values  저장할 절대값
     * @return Payment 갱신된 결제 레코드
     */
    public function update(Payment $payment, array $values): Payment
    {
        DB::transaction(function () use ($payment, $values): void {
            $payment->forceFill($values);
            $payment->save();
        });

        return $payment;
    }

    /**
     * PENDING 결제 레코드를 새로 만든다. payment_id 충돌 시 다시 시도한다.
     *
     * @param  Model  $billable  결제를 소유할 Billable 모델
     * @param  string  $customerId  PortOne 고객 ID
     * @param  int  $amount  결제 금액(원)
     * @param  string  $orderName  주문명
     * @return Payment 생성된 PENDING 결제 레코드
     */
    public function createPending(Model $billable, string $customerId, int $amount, string $orderName): Payment
    {
        while (true) {
            $paymentId = (string) Str::uuid();

            try {
                return Payment::query()->create([
                    'payment_id' => $paymentId,
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $this->billableId($billable),
                    'customer_id' => $customerId,
                    'order_name' => $orderName,
                    'amount' => $amount,
                    'status' => 'PENDING',
                    'cancelled_amount' => 0,
                    'paid_at' => null,
                ]);
            } catch (QueryException $exception) {
                $paymentIdExists = DB::table('portone_payments')
                    ->where('payment_id', $paymentId)
                    ->exists();

                if (! $paymentIdExists) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Billable 모델의 문자열 키를 반환한다.
     *
     * @param  Model  $billable  검사할 Billable 모델
     * @return string 문자열로 변환한 모델 키
     *
     * @throws \InvalidArgumentException 키가 문자열도 정수도 아닐 때
     */
    private function billableId(Model $billable): string
    {
        $key = $billable->getKey();

        if (! is_string($key) && ! is_int($key)) {
            throw new \InvalidArgumentException('A Billable model must have a string or integer key.');
        }

        return (string) $key;
    }
}
