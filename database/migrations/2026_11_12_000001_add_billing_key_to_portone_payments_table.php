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
            $table->foreignId('billing_key_id')->nullable()->after('receipt_url')->constrained('portone_billing_keys');
        });
    }

    public function down(): void
    {
        Schema::table('portone_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('billing_key_id');
        });
    }
};
