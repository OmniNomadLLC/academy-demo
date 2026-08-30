<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('students')) {
            return;
        }

        $indexes = [];

        if (Schema::hasColumn('students', 'location')) {
            $indexes['location'] = 'students_location_idx';
        }

        if (Schema::hasColumn('students', 'acuity_category')) {
            $indexes['acuity_category'] = 'students_acuity_category_idx';
        }

        if (Schema::hasColumn('students', 'attendance_rate')) {
            $indexes['attendance_rate'] = 'students_attendance_rate_idx';
        }

        if (empty($indexes)) {
            return;
        }

        Schema::table('students', function (Blueprint $table) use ($indexes) {
            foreach ($indexes as $column => $name) {
                try {
                    $table->index($column, $name);
                } catch (\Throwable $e) {
                    // Ignore duplicate index exceptions
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('students')) {
            return;
        }

        Schema::table('students', function (Blueprint $table) {
            foreach ([
                'students_location_idx',
                'students_acuity_category_idx',
                'students_attendance_rate_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable $e) {
                    // Index already absent
                }
            }
        });
    }
};
