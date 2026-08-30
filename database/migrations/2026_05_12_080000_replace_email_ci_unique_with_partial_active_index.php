<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('students', 'email_ci_active')) {
            if (DB::getDriverName() === 'sqlite') {
                // SQLite never got the email_ci generated column (2025_09_05_204000
                // used a functional LOWER(email) index there instead), so derive
                // straight from email. Same semantics as the MySQL expression below.
                DB::statement("
                    ALTER TABLE students
                    ADD COLUMN email_ci_active VARCHAR(255)
                        GENERATED ALWAYS AS (
                            CASE WHEN deleted_at IS NULL AND archived_at IS NULL
                                 THEN LOWER(email) ELSE NULL END
                        ) VIRTUAL
                ");

                // Drop the legacy always-on unique guard; it would block the
                // archived-or-soft-deleted re-create flow this migration enables.
                DB::statement('DROP INDEX IF EXISTS students_email_lower_unique');
            } else {
                DB::statement('
                    ALTER TABLE students
                    ADD COLUMN email_ci_active VARCHAR(255)
                        AS (IF(deleted_at IS NULL AND archived_at IS NULL, email_ci, NULL))
                        VIRTUAL
                ');
            }
        }

        if ($this->indexExists('students', 'students_email_ci_unique')) {
            $this->dropIndexStatement('students_email_ci_unique');
        }

        // The email_norm_unique_guard predates email_ci_unique and protects the same
        // canonical lowercased email shape. With email_ci_active replacing both as the
        // active-only canonical guard, drop the legacy guard too — keeping it would
        // block the archived-or-soft-deleted re-create flow this migration enables.
        if ($this->indexExists('students', 'students_email_norm_unique_guard')) {
            $this->dropIndexStatement('students_email_norm_unique_guard');
        }

        if (! $this->indexExists('students', 'students_email_ci_active_unique')) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('CREATE UNIQUE INDEX students_email_ci_active_unique ON students (email_ci_active)');
            } else {
                DB::statement('
                    ALTER TABLE students
                    ADD UNIQUE INDEX students_email_ci_active_unique (email_ci_active)
                ');
            }
        }
    }

    protected function dropIndexStatement(string $index): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        } else {
            DB::statement("ALTER TABLE students DROP INDEX {$index}");
        }
    }

    public function down(): void
    {
        if ($this->indexExists('students', 'students_email_ci_active_unique')) {
            DB::statement('ALTER TABLE students DROP INDEX students_email_ci_active_unique');
        }

        if (Schema::hasColumn('students', 'email_ci_active')) {
            DB::statement('ALTER TABLE students DROP COLUMN email_ci_active');
        }

        if (! $this->indexExists('students', 'students_email_ci_unique')) {
            DB::statement('ALTER TABLE students ADD UNIQUE INDEX students_email_ci_unique (email_ci)');
        }

        if (! $this->indexExists('students', 'students_email_norm_unique_guard')) {
            DB::statement('ALTER TABLE students ADD UNIQUE INDEX students_email_norm_unique_guard (email_norm)');
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        // Driver-agnostic (SHOW INDEX is MySQL-only and broke SQLite test runs).
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $idx) => strcasecmp($idx['name'], $index) === 0);
    }
};
