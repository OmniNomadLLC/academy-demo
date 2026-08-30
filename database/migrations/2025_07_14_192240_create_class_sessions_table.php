<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->onDelete('cascade');
            $table->foreignId('teacher_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('acuity_appointment_id')->unique()->nullable();
            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time');
            // String rather than enum: 2026_01_07_200500 adds 'confirmed' via
            // ALTER on MySQL/Postgres, which SQLite's CHECK-based enum cannot do.
            $table->string('status', 20)->default('scheduled');
            $table->integer('max_students')->default(10);
            $table->text('notes')->nullable();
            $table->json('acuity_data')->nullable(); // Store raw Acuity appointment data
            $table->timestamps();
            
            // Indexes for performance
            $table->index('session_date');
            $table->index('teacher_id');
            $table->index('school_class_id');
            $table->index('status');
            $table->index(['session_date', 'teacher_id']); // For teacher dashboard
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_sessions');
    }
};
