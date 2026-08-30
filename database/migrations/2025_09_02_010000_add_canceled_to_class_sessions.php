<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('class_sessions', 'canceled')) {
                $table->boolean('canceled')->nullable()->default(false)->after('status');
            }
            if (Schema::hasColumn('class_sessions', 'student_id')) {
                $table->index(['student_id', 'session_date'], 'class_sessions_student_date_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'canceled')) {
                $table->dropColumn('canceled');
            }
            if (Schema::hasColumn('class_sessions', 'student_id')) {
                $table->dropIndex('class_sessions_student_date_idx');
            }
        });
    }
};

