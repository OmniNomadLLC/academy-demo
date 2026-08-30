<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('assessment_template_id')->constrained('assessment_templates')->restrictOnDelete();
            $table->foreignId('assessed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('assessed_at');
            $table->decimal('average_score', 5, 2)->nullable();
            $table->text('overall_comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_assessments');
    }
};
