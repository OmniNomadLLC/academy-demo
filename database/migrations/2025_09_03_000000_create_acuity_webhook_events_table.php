<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('acuity_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint')->unique();
            $table->string('action')->nullable();
            $table->string('appointment_id')->nullable();
            $table->string('calendar_id')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->json('payload')->nullable();
            $table->index(['appointment_id']);
            $table->index(['calendar_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acuity_webhook_events');
    }
};

