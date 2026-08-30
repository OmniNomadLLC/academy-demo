<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('class_sessions', 'student_email')) {
                $table->string('student_email', 191)->nullable()->after('client_email');
                $table->index('student_email', 'class_sessions_student_email_idx');
            }
        });

        // Functional lowercase index for students.email when supported
        $driver = DB::getDriverName();
        if (Schema::hasTable('students') && Schema::hasColumn('students', 'email')) {
            if (in_array($driver, ['mysql', 'mariadb'])) {
                try {
                    DB::statement('CREATE INDEX IF NOT EXISTS students_email_ci ON students ((LOWER(email)))');
                } catch (\Throwable $e) {
                    // Fallback: simple index
                    try {
                        Schema::table('students', function (Blueprint $table) {
                            $table->index('email', 'students_email_idx');
                        });
                    } catch (\Throwable $e2) {}
                }
            } elseif ($driver === 'sqlite') {
                try {
                    Schema::table('students', function (Blueprint $table) {
                        $table->index('email', 'students_email_idx');
                    });
                } catch (\Throwable $e) {}
            }
        }
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('class_sessions', 'student_email')) {
                $table->dropIndex('class_sessions_student_email_idx');
                $table->dropColumn('student_email');
            }
        });
        // do not drop student email index on students in down
    }
};

