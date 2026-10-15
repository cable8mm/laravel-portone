<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v0.4.0부터 PAID 확정 때 paid_amount를 저장한다. 그 전에 결제된 레코드는 PortOne 실결제액이
 * 준비 금액과 같을 때만 PAID가 됐으므로 amount로 채운다.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('portone_payments')
            ->whereIn('status', ['PAID', 'PARTIALLY_CANCELLED', 'CANCELLED'])
            ->whereNull('paid_amount')
            ->update(['paid_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        // 백필 전 값(null)과 새로 저장된 값을 구분할 수 없으므로 되돌리지 않는다.
    }
};
