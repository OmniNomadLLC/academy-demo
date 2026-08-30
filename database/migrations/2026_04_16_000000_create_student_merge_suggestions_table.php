<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_merge_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_a_id')->constrained('students')->cascadeOnDelete(); // existing student
            $table->foreignId('student_b_id')->constrained('students')->cascadeOnDelete(); // newly created student
            $table->string('reason')->default('same_name'); // same_name | same_acuity_client_id
            $table->string('status')->default('pending');   // pending | merged | dismissed
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['student_a_id', 'student_b_id']); // no double suggestions
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_merge_suggestions');
    }
};
