<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 고객 기록 Eloquent 모델.
 *
 * Billable 모델당 1건이며, PortOne 결제창에 전달할 customer_id를 보관한다.
 *
 * @property string $billable_type
 * @property string $billable_id
 * @property string $customer_id
 */
class Customer extends Model
{
    protected $table = 'portone_customers';

    protected $guarded = [];

    public $timestamps = false;
}
