<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_assessment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_assessment_id')
                ->constrained('student_assessments')
                ->cascadeOnDelete();
            $table->foreignId('template_section_id')
                ->nullable()
                ->constrained('assessment_sections')
                ->nullOnDelete();
            $table->foreignId('template_question_id')
                ->nullable()
                ->constrained('assessment_questions')
                ->nullOnDelete();
            $table->string('section_name');
            $table->text('question_text');
            $table->integer('max_score');
            $table->decimal('weight', 5, 2)->nullable();
            $table->integer('sort_order');
            $table->integer('template_version');
            $table->timestamps();

            $table->index('student_assessment_id');
            $table->index(['student_assessment_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_assessment_items');
    }
};
