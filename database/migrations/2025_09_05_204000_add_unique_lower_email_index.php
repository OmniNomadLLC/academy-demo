<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            // SQLite supports expression indexes
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS students_email_lower_unique ON students (LOWER(email))');
        } elseif (in_array($driver, ['mysql','mariadb'])) {
            // Add a generated column for CI email and unique index
            try {
                DB::statement("ALTER TABLE `students` ADD COLUMN email_ci VARCHAR(255) GENERATED ALWAYS AS (LOWER(email)) STORED");
            } catch (\Throwable $e) {
                // column may already exist
            }
            try {
                DB::statement('CREATE UNIQUE INDEX students_email_ci_unique ON students (email_ci)');
            } catch (\Throwable $e) {
                // index may already exist
            }
        } else {
            // Postgres and others: try functional unique index
            try {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS students_email_lower_unique ON students ((LOWER(email)))');
            } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS students_email_lower_unique');
        } elseif (in_array($driver, ['mysql','mariadb'])) {
            try { DB::statement('DROP INDEX students_email_ci_unique ON students'); } catch (\Throwable $e) {}
            try { DB::statement('ALTER TABLE `students` DROP COLUMN email_ci'); } catch (\Throwable $e) {}
        } else {
            try { DB::statement('DROP INDEX IF EXISTS students_email_lower_unique'); } catch (\Throwable $e) {}
        }
    }
};

