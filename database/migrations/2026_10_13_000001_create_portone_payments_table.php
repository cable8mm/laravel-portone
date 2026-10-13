<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portone_payments', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_id')->unique();
            $table->string('billable_type');
            $table->string('billable_id');
            $table->string('customer_id');
            $table->string('order_name');
            $table->unsignedBigInteger('amount');
            $table->string('status');
            $table->unsignedBigInteger('cancelled_amount')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portone_payments');
    }
};
