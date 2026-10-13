<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone;

use Cable8mm\LaravelPortone\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Billable 모델별 PortOne 고객 ID를 보장하는 레지스트리.
 *
 * Billable 참조(모델 종류 + 키)당 고객 레코드 1건을 유지한다.
 * 동시 호출이 겹쳐도 하나의 레코드로 수렴한다.
 */
class CustomerRegistry
{
    /**
     * 이 모델에 연결된 고객 레코드가 있는지 확인한다.
     *
     * @param  Model  $billable  검사할 Billable 모델
     * @return bool 고객 레코드가 있으면 true
     */
    public function has(Model $billable): bool
    {
        return $this->query($billable)->exists();
    }

    /**
     * 이 모델의 고객 ID를 조회한다.
     *
     * @param  Model  $billable  조회할 Billable 모델
     * @return string|null 고객 ID, 없으면 null
     */
    public function findId(Model $billable): ?string
    {
        $customerId = $this->query($billable)->value('customer_id');

        return is_string($customerId) ? $customerId : null;
    }

    /**
     * 이 모델의 고객 ID를 만들거나 기존 값을 반환한다.
     *
     * @param  Model  $billable  대상 Billable 모델
     * @return string 고객 ID (UUID 문자열)
     */
    public function ensureId(Model $billable): string
    {
        if (($customerId = $this->findId($billable)) !== null) {
            return $customerId;
        }

        try {
            $customer = DB::transaction(fn (): Customer => Customer::query()->create([
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $this->billableId($billable),
                'customer_id' => (string) Str::uuid(),
            ]));

            $customerId = $customer->getAttribute('customer_id');

            if (! is_string($customerId)) {
                throw new \UnexpectedValueException('The created Customer record has no Customer ID.');
            }

            return $customerId;
        } catch (QueryException $exception) {
            $customerId = DB::table('portone_customers')
                ->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $this->billableId($billable))
                ->value('customer_id');

            if (is_string($customerId)) {
                return $customerId;
            }

            throw $exception;
        }
    }

    /** @return Builder<Customer> Billable 범위로 좁힌 고객 쿼리 */
    private function query(Model $billable): Builder
    {
        return Customer::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $this->billableId($billable));
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
