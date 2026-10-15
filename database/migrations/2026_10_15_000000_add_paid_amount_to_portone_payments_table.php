<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portone_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('paid_amount')->nullable()->after('cancelled_amount');
        });
    }

    public function down(): void
    {
        Schema::table('portone_payments', function (Blueprint $table): void {
            $table->dropColumn('paid_amount');
        });
    }
};
