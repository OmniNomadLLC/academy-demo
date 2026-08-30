<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('class_sessions')) {
            return;
        }

        if (Schema::hasColumn('class_sessions', 'student_id')) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table) {
            // Nullable because many sessions are linked by email only
            $table->foreignId('student_id')
                ->nullable()
                ->after('school_class_id')
                ->constrained('students')
                ->nullOnDelete();
        });

        // Add indexes expected elsewhere in the codebase
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->index('student_id', 'class_sessions_student_id_idx');

            if (Schema::hasColumn('class_sessions', 'session_date')) {
                $table->index(['student_id', 'session_date'], 'class_sessions_student_date_idx');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('class_sessions') || !Schema::hasColumn('class_sessions', 'student_id')) {
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table) {
            // Drop explicit indexes before removing the column
            $table->dropIndex('class_sessions_student_id_idx');
            $table->dropIndex('class_sessions_student_date_idx');

            $table->dropForeign(['student_id']);
            $table->dropColumn('student_id');
        });
    }
};
