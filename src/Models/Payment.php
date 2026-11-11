<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * 한 번만 결제되는 Billable 모델(allowsOnlyOnePaidPayment())에 이미 결제된 레코드가 있으면 두 번째로 결제된 건은
 * PENDING·EXPIRED → DUPLICATE_CANCELLED(자동 전액 취소 성공) 또는 DUPLICATE(취소 실패, 운영자가 처리)가 된다.
 * AMOUNT_MISMATCH·DUPLICATE는 PortOne에서 취소된 것이 동기화되면 각각 AMOUNT_MISMATCH_CANCELLED·DUPLICATE_CANCELLED가 된다.
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
 * @property int|null $paid_amount PortOne이 확인해 준 실결제액(원). PAID 확정과 금액 불일치·중복 결제 때 저장하며, PortOne이 아직
 *                                 실결제액을 알려 주지 않은 PENDING·EXPIRED는 null이다
 * @property Carbon|null $paid_at
 * @property string|null $receipt_url PortOne 영수증 URL(receiptUrl). 그 레코드에 마지막으로 쓴 PortOne 조회가 알려 준 값이며,
 *                                    영수증 URL이 없는 조회는 이전 값을 그대로 둔다
 * @property int|null $billing_key_id 빌링키 결제(chargeBillingKey())로 만든 레코드면 결제한 빌링키 기록, 브라우저 결제면 null
 * @property int|null $subscription_id 구독 기간을 낸 결제면 그 구독 기록, 아니면 null
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
     * 이 결제가 결제한 빌링키 기록. 브라우저 결제면 없다.
     *
     * @return BelongsTo<BillingKey, $this>
     */
    public function billingKey(): BelongsTo
    {
        return $this->belongsTo(BillingKey::class);
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
