<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
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
     * @return array{paymentId: string, orderName: string, totalAmount: int, customerId: string}
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
