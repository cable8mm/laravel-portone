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
            $table->text('receipt_url')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('portone_payments', function (Blueprint $table): void {
            $table->dropColumn('receipt_url');
        });
    }
};
