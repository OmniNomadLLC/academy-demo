<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'])) {
            DB::statement("ALTER TABLE `users` MODIFY `role` VARCHAR(32) NOT NULL DEFAULT 'teacher'");
        } elseif ($driver === 'pgsql') {
            // Assume role is text/varchar already on PG in this project; no-op.
        } else {
            // SQLite or others: ensure the column exists as TEXT; schema builder change() not supported widely.
            // Best-effort no-op.
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'])) {
            // Revert to enum without manager/super_admin. Note: data using other values may fail.
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','teacher','report_recipient') NOT NULL DEFAULT 'teacher'");
        }
    }
};

