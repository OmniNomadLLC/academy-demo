<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (! Schema::hasColumn('students', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index();
            }
            if (! Schema::hasColumn('students', 'archived_reason')) {
                $table->string('archived_reason', 32)->nullable()->default(null);
            }
            if (! Schema::hasColumn('students', 'previous_student_id')) {
                $table->foreignId('previous_student_id')
                    ->nullable()
                    ->constrained('students')
                    ->nullOnDelete();
            }
        });

        if (! $this->indexExists('students', 'idx_students_location_archived')) {
            Schema::table('students', function (Blueprint $table) {
                $table->index(['location', 'archived_at'], 'idx_students_location_archived');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('students', 'idx_students_location_archived')) {
            Schema::table('students', function (Blueprint $table) {
                $table->dropIndex('idx_students_location_archived');
            });
        }

        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'previous_student_id')) {
                $table->dropConstrainedForeignId('previous_student_id');
            }
            if (Schema::hasColumn('students', 'archived_reason')) {
                $table->dropColumn('archived_reason');
            }
            if (Schema::hasColumn('students', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
    }

    protected function indexExists(string $table, string $index): bool
    {
        // Driver-agnostic (SHOW INDEX is MySQL-only and broke SQLite test runs).
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $idx) => strcasecmp($idx['name'], $index) === 0);
    }
};
