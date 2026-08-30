<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_records')) {
            return;
        }

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->enum('status', ['present', 'absent', 'late', 'cancelled'])->default('present');
            $table->timestamp('marked_at')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Ensure one attendance record per student per session
            $table->unique(['class_session_id', 'student_id']);
            
            // Indexes for analytics and reporting
            $table->index('status');
            $table->index('marked_at');
            $table->index(['student_id', 'status']); // For student attendance rates
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
