<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('class_sessions', 'teacher_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE class_sessions MODIFY teacher_id BIGINT UNSIGNED NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE class_sessions ALTER COLUMN teacher_id DROP NOT NULL');
            return;
        }

        // Fallback for other drivers (requires doctrine/dbal for change()).
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('class_sessions', 'teacher_id')) {
            return;
        }

        if (DB::table('class_sessions')->whereNull('teacher_id')->exists()) {
            // Cannot safely revert to NOT NULL while null data exists.
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE class_sessions MODIFY teacher_id BIGINT UNSIGNED NOT NULL');
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE class_sessions ALTER COLUMN teacher_id SET NOT NULL');
            return;
        }

        Schema::table('class_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_id')->nullable(false)->change();
        });
    }
};
