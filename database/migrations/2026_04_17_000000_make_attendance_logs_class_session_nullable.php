<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['class_session_id']);
            $table->unsignedBigInteger('class_session_id')->nullable()->change();
            $table->foreign('class_session_id')
                ->references('id')
                ->on('class_sessions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropForeign(['class_session_id']);
            $table->unsignedBigInteger('class_session_id')->nullable(false)->change();
            $table->foreign('class_session_id')
                ->references('id')
                ->on('class_sessions')
                ->cascadeOnDelete();
        });
    }
};
