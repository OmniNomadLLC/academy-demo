<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Guard for sqlite quirks; still allow simple index creation if possible
        $driver = DB::getDriverName();

        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                if (Schema::hasColumn('students', 'external_id')) {
                    $table->index('external_id', 'students_external_id_idx');
                }
                if (Schema::hasColumn('students', 'email')) {
                    $table->index('email', 'students_email_idx');
                }
                if (Schema::hasColumn('students', 'acuity_client_id')) {
                    // If already unique, skip duplicative index
                    // Laravel will ignore duplicate index names gracefully
                    $table->index('acuity_client_id', 'students_acuity_client_id_idx');
                }
            });
        }

        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table) {
                if (Schema::hasColumn('class_sessions', 'student_id')) {
                    $table->index('student_id', 'class_sessions_student_id_idx');
                }
                if (Schema::hasColumn('class_sessions', 'session_date')) {
                    $table->index('session_date', 'class_sessions_session_date_idx');
                }
            });
        }

        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                if (Schema::hasColumn('jobs', 'reserved_at')) {
                    $table->index('reserved_at', 'jobs_reserved_at_idx');
                }
                if (Schema::hasColumn('jobs', 'available_at')) {
                    $table->index('available_at', 'jobs_available_at_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('students')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropIndex('students_external_id_idx');
                $table->dropIndex('students_email_idx');
                $table->dropIndex('students_acuity_client_id_idx');
            });
        }

        if (Schema::hasTable('class_sessions')) {
            Schema::table('class_sessions', function (Blueprint $table) {
                $table->dropIndex('class_sessions_student_id_idx');
                $table->dropIndex('class_sessions_session_date_idx');
            });
        }

        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropIndex('jobs_reserved_at_idx');
                $table->dropIndex('jobs_available_at_idx');
            });
        }
    }
};

