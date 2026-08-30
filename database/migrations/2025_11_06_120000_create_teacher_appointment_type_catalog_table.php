<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teacher_appointment_type_catalog')) {
            return;
        }

        Schema::create('teacher_appointment_type_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('acuity_calendar_id');
            $table->string('calendar_norm')->nullable();
            $table->string('calendar_label');
            $table->string('acuity_appointment_type_id');
            $table->string('appointment_type_name');
            $table->unsignedBigInteger('last_seen_session_id')->nullable();
            $table->date('last_seen_session_date')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['acuity_calendar_id', 'acuity_appointment_type_id'], 'teacher_type_catalog_calendar_type_unique');
            $table->index('calendar_norm', 'teacher_type_catalog_calendar_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_appointment_type_catalog');
    }
};
