<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portone_billing_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_id')->unique();
            $table->string('billable_type');
            $table->string('billable_id');
            $table->string('customer_id');
            $table->string('issue_name');
            // The billing key, encrypted with the application key; null until it is issued.
            $table->text('billing_key')->nullable();
            $table->string('status');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portone_billing_keys');
    }
};
