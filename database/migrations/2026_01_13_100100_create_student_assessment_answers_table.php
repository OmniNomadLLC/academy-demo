<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_assessment_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_assessment_id')
                ->constrained('student_assessments')
                ->cascadeOnDelete();
            $table->foreignId('assessment_question_id')
                ->constrained('assessment_questions')
                ->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_assessment_answers');
    }
};
