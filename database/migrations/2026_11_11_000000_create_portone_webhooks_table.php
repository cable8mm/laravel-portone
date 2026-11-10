<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portone_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('type');
            $table->longText('body');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portone_webhooks');
    }
};
