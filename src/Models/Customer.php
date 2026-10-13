<?php

declare(strict_types=1);

namespace Cable8mm\LaravelPortone\Models;

use Illuminate\Database\Eloquent\Model;

/**
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
