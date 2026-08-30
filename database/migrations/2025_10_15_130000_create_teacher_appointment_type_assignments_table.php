<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_appointment_type_assignments')) {
            return;
        }

        Schema::create('teacher_appointment_type_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('acuity_calendar_id')->nullable();
            $table->string('acuity_appointment_type_id');
            $table->string('appointment_type_name')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'acuity_appointment_type_id'], 'teacher_assignments_user_type_unique');
            $table->unique(['acuity_calendar_id', 'acuity_appointment_type_id'], 'teacher_assignments_calendar_type_unique');
            $table->index('acuity_calendar_id', 'teacher_assignments_calendar_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_appointment_type_assignments');
    }
};
