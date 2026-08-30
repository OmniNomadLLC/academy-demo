<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `class_sessions` MODIFY `status` ENUM('scheduled','confirmed','completed','cancelled') NOT NULL DEFAULT 'scheduled'");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TYPE class_sessions_status_enum ADD VALUE IF NOT EXISTS 'confirmed'");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `class_sessions` MODIFY `status` ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled'");

            return;
        }

        if ($driver === 'pgsql') {
            // Postgres cannot easily drop enum values without recreating the type; no-op on rollback.
        }
    }
};
