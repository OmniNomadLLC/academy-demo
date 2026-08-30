<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('level')->nullable(); // e.g., 'Beginner', 'Intermediate', 'Advanced'
            $table->string('language')->nullable(); // e.g., 'Spanish', 'French', 'English'
            $table->foreignId('teacher_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('acuity_appointment_type_id')->nullable();
            $table->integer('max_students')->default(10);
            $table->integer('duration_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Indexes
            $table->index('teacher_id');
            $table->index('is_active');
            $table->index(['language', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};