<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portone_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('billable_type');
            $table->string('billable_id');
            $table->foreignId('billing_key_id')->constrained('portone_billing_keys');
            $table->string('name');
            $table->unsignedBigInteger('amount');
            $table->string('order_name');
            $table->string('interval');
            $table->string('status');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->timestamp('next_charge_at');
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['billable_type', 'billable_id']);
            $table->index(['status', 'next_charge_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portone_subscriptions');
    }
};
