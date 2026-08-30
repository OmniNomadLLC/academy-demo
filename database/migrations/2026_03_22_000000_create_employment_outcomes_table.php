<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->enum('job_status', ['none', 'interviewing', 'employed', 'sustained'])->default('none');
            $table->date('job_start_date')->nullable();
            $table->date('job_end_date')->nullable();
            $table->unsignedTinyInteger('hours_per_week')->nullable();
            $table->string('employer_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_outcomes');
    }
};
