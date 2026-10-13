<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 결제 기록 Eloquent 모델.
 *
 * 한 Billable 모델이 소유하며, PortOne 결제 식별자(payment_id)는 전역 유니크이다.
 * 상태 전이: PENDING → PAID → PARTIALLY_CANCELLED → CANCELLED
 * (PAID에서 바로 CANCELLED로 전액 환불될 수도 있다).
 *
 * @property string $payment_id
 * @property string $billable_type
 * @property string $billable_id
 * @property string $customer_id
 * @property string $order_name
 * @property int $amount
 * @property string $status
 * @property int $cancelled_amount
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payment extends Model
{
    protected $table = 'portone_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'cancelled_amount' => 'integer',
            'paid_at' => 'datetime',
        ];
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
