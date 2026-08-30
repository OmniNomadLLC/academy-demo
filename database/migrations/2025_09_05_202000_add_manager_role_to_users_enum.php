<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        // Only needed for MySQL/MariaDB where ENUM is enforced.
        if (in_array($driver, ['mysql', 'mariadb'])) {
            // Expand enum to include 'manager'.
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','teacher','manager','report_recipient') NOT NULL DEFAULT 'teacher'");
        }
        // For SQLite/Postgres, the column is typically TEXT/VARCHAR; no action required.
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'])) {
            // Revert to original enum without 'manager'. Users with 'manager' should be migrated manually if needed.
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','teacher','report_recipient') NOT NULL DEFAULT 'teacher'");
        }
    }
};

