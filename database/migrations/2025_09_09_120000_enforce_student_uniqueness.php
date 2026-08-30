<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('students')) {
            return; // guard for fresh installs without students table yet
        }

        Schema::table('students', function (Blueprint $table) {
            // Add normalized email column
            if (!Schema::hasColumn('students', 'email_norm')) {
                $driver = DB::getDriverName();
                if (in_array($driver, ['mysql', 'mariadb'])) {
                    // Stored generated column will maintain normalization automatically
                    $table->string('email_norm')->nullable()->storedAs('LOWER(COALESCE(email, ""))');
                } else {
                    // Other drivers: materialized column; app layer will maintain
                    $table->string('email_norm')->nullable();
                }
            }
        });

        // Unique index on acuity_client_id (allows multiple NULLs on MySQL which is acceptable)
        if (Schema::hasColumn('students', 'acuity_client_id')) {
            try {
                Schema::table('students', function (Blueprint $table) {
                    $table->unique(['acuity_client_id'], 'students_acuity_client_id_unique_guard');
                });
            } catch (\Throwable $e) {
                // ignore if exists
            }
        }

        // Unique constraint for normalized email
        $driver = DB::getDriverName();
        if (Schema::hasColumn('students', 'email')) {
            try {
                if (in_array($driver, ['mysql', 'mariadb'])) {
                    if (Schema::hasColumn('students', 'email_norm')) {
                        Schema::table('students', function (Blueprint $table) {
                            $table->unique(['email_norm'], 'students_email_norm_unique_guard');
                        });
                    }
                } elseif ($driver === 'sqlite') {
                    // Expression unique index on lower(email)
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS students_email_lower_unique ON students (LOWER(email))');
                } elseif ($driver === 'pgsql') {
                    // Partial unique index on lower(email) where email is not null
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS students_email_lower_unique ON students ((LOWER(email))) WHERE email IS NOT NULL');
                } else {
                    // Fallback: try unique on email_norm if available
                    if (Schema::hasColumn('students', 'email_norm')) {
                        Schema::table('students', function (Blueprint $table) {
                            $table->unique(['email_norm'], 'students_email_norm_unique_guard');
                        });
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Best-effort backfill email_norm for non-MySQL drivers
        try {
            if (!in_array($driver, ['mysql', 'mariadb']) && Schema::hasColumn('students', 'email_norm')) {
                DB::table('students')->whereNull('email_norm')->update([
                    'email_norm' => DB::raw('LOWER(COALESCE(email, ""))')
                ]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function down(): void
    {
        // Non-destructive: keep columns/indexes to avoid accidental duplicate creation on rollback
    }
};

