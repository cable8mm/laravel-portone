<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 결제 기록 Eloquent 모델.
 *
 * 한 Billable 모델이 소유하며, PortOne 결제 식별자(payment_id)는 전역 유니크이다.
 * 상태 전이: PENDING → PAID → PARTIALLY_CANCELLED → CANCELLED
 * (PAID에서 바로 CANCELLED로 전액 환불될 수도 있다).
 * 버려진 결제는 PENDING → EXPIRED가 되고, 나중에 결제되면 EXPIRED → PAID로 복구된다.
 * PortOne 실결제액이 준비 금액과 다르면 PENDING·EXPIRED → AMOUNT_MISMATCH_CANCELLED
 * (자동 전액 취소 성공) 또는 AMOUNT_MISMATCH(취소 실패, 운영자가 처리)가 되고 더 바뀌지 않는다.
 * model:prune은 보관 기간이 지난 EXPIRED 레코드만 지운다.
 *
 * @property string $payment_id
 * @property string $billable_type
 * @property string $billable_id
 * @property string $customer_id
 * @property string $order_name
 * @property int $amount
 * @property string $status
 * @property int $cancelled_amount
 * @property int|null $paid_amount 금액 불일치 때 PortOne이 알려 준 실결제액, 그 밖에는 null
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payment extends Model
{
    use MassPrunable;

    protected $table = 'portone_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'cancelled_amount' => 'integer',
            'paid_amount' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * model:prune이 지울 레코드: portone.expired_retention_days일보다 오래된 EXPIRED 결제만.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = config('portone.expired_retention_days');

        return static::query()
            ->where('status', 'EXPIRED')
            ->where('updated_at', '<', now()->subDays(is_numeric($days) ? (int) $days : 90));
    }

    /**
     * 브라우저 결제창에 전달할 결제 파라미터를 반환한다.
     *
     * PortOne requestPayment 호출에 그대로 사용한다.
     *
     * @return array{paymentId: string, orderName: string, totalAmount: int, customerId: string} 결제창 파라미터
     */
    public function checkoutParameters(): array
    {
        return [
            'paymentId' => $this->payment_id,
            'orderName' => $this->order_name,
            'totalAmount' => $this->amount,
            'customerId' => $this->customer_id,
        ];
    }
}
