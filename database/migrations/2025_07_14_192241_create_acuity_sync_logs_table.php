<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acuity_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type'); // 'appointments', 'clients', 'appointment_types'
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->integer('records_processed')->default(0);
            $table->integer('records_created')->default(0);
            $table->integer('records_updated')->default(0);
            $table->text('error_message')->nullable();
            $table->json('sync_data')->nullable(); // Store additional sync information
            $table->timestamps();
            
            // Indexes
            $table->index('sync_type');
            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acuity_sync_logs');
    }
};