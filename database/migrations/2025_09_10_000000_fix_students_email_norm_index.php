<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Ensure email_norm column exists
        if (!Schema::hasColumn('students', 'email_norm')) {
            Schema::table('students', function (Blueprint $table) {
                $table->string('email_norm')->nullable()->after('email');
            });
        }

        // 2) Drop any unique indexes enforcing uniqueness on lower(email) or email_norm
        $driver = DB::getDriverName();
        try {
            if (in_array($driver, ['mysql', 'mariadb'])) {
                // MySQL index names may vary; attempt common ones
                DB::statement("DROP INDEX IF EXISTS students_email_ci_unique ON students");
                DB::statement("DROP INDEX IF EXISTS students_email_lower_unique ON students");
                DB::statement("DROP INDEX IF EXISTS students_email_norm_unique_guard ON students");
            } else {
                DB::statement('DROP INDEX IF EXISTS students_email_lower_unique');
                DB::statement('DROP INDEX IF EXISTS students_email_ci_unique');
                DB::statement('DROP INDEX IF EXISTS students_email_norm_unique_guard');
            }
        } catch (\Throwable $e) {
            // Best-effort; ignore if names differ
        }

        // 3) Backfill email_norm as NULLIF(LOWER(TRIM(COALESCE(email, ''))), '')
        if (Schema::hasColumn('students', 'email_norm')) {
            DB::table('students')->update([
                'email_norm' => DB::raw("NULLIF(LOWER(TRIM(COALESCE(email, ''))), '')"),
            ]);
        }

        // 4) Create NON-UNIQUE index on email_norm (partial when supported)
        try {
            if ($driver === 'sqlite') {
                DB::statement("CREATE INDEX IF NOT EXISTS students_email_lower_idx ON students (email_norm) WHERE email_norm IS NOT NULL AND email_norm <> ''");
            } elseif ($driver === 'pgsql') {
                DB::statement("CREATE INDEX IF NOT EXISTS students_email_lower_idx ON students (email_norm) WHERE email_norm IS NOT NULL AND email_norm <> ''");
            } else { // mysql/mariadb
                Schema::table('students', function (Blueprint $table) {
                    try { $table->index('email_norm', 'students_email_lower_idx'); } catch (\Throwable $e) {}
                });
            }
        } catch (\Throwable $e) {
            // If creation fails, ignore to avoid breaking migrations; lookups will still work without index
        }

        // 5) Ensure acuity_client_id remains unique if present
        if (Schema::hasColumn('students', 'acuity_client_id')) {
            try {
                if (in_array($driver, ['mysql', 'mariadb'])) {
                    DB::statement("ALTER TABLE students ADD UNIQUE INDEX students_acuity_client_id_unique (acuity_client_id)");
                } else {
                    Schema::table('students', function (Blueprint $table) {
                        try { $table->unique('acuity_client_id', 'students_acuity_client_id_unique'); } catch (\Throwable $e) {}
                    });
                }
            } catch (\Throwable $e) {
                // ignore if already unique
            }
        }
    }

    public function down(): void
    {
        // Drop non-unique email_norm index
        $driver = DB::getDriverName();
        try {
            if (in_array($driver, ['mysql', 'mariadb'])) {
                DB::statement('DROP INDEX IF EXISTS students_email_lower_idx ON students');
            } else {
                DB::statement('DROP INDEX IF EXISTS students_email_lower_idx');
            }
        } catch (\Throwable $e) {}

        // Optional: do not re-create unique constraints to avoid reintroducing violations
    }
};

