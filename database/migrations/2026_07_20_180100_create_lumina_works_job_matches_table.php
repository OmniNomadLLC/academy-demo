<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumina_works_job_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lumina_works_job_id')->constrained('lumina_works_jobs')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('reason', 500)->nullable();
            $table->string('score_source', 16)->default('keyword');
            $table->decimal('distance_km', 6, 1)->nullable();
            $table->boolean('english_suitable')->default(true);
            $table->boolean('is_mandated')->default(false);
            $table->string('status', 16)->default('surfaced');
            $table->timestamp('surfaced_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'lumina_works_job_id']);
            $table->index(['student_id', 'status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumina_works_job_matches');
    }
};
