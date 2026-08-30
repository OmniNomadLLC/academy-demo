<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumina_works_companion_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lumina_works_job_id')->constrained('lumina_works_jobs')->cascadeOnDelete();
            $table->string('english_band', 16);
            $table->string('source', 16)->default('fallback');
            $table->json('content');
            $table->timestamps();

            $table->unique(['student_id', 'lumina_works_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lumina_works_companion_packs');
    }
};
