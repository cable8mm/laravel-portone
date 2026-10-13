<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portone_customers', function (Blueprint $table): void {
            $table->id();
            $table->string('billable_type');
            $table->string('billable_id');
            $table->string('customer_id')->unique();
            $table->unique(['billable_type', 'billable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portone_customers');
    }
};
