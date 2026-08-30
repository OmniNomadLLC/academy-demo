<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('class_session_id');
            $table->unsignedBigInteger('student_id');
            $table->enum('status', ['present','late','absent'])->index();
            $table->unsignedBigInteger('marked_by')->nullable();
            $table->timestamp('marked_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->string('region', 16)->default('UK');
            $table->timestamps();

            $table->unique(['class_session_id','student_id']);
            $table->index('class_session_id');
            $table->index('student_id');

            $table->foreign('class_session_id')->references('id')->on('class_sessions')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            $table->foreign('marked_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};

