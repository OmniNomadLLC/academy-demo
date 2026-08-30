<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumina_works_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lumina_works_job_id')->constrained('lumina_works_jobs')->cascadeOnDelete();
            $table->foreignId('lumina_works_job_match_id')->nullable()->constrained('lumina_works_job_matches')->nullOnDelete();
            $table->string('status', 24)->default('applied');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('interview_at')->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'lumina_works_job_id']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumina_works_applications');
    }
};
