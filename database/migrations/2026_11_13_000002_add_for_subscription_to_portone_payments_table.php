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
            $table->boolean('for_subscription')->default(false)->after('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('portone_payments', function (Blueprint $table): void {
            $table->dropColumn('for_subscription');
        });
    }
};
