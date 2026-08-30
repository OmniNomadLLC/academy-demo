<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance_records')) {
            return;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }
        $databaseName = $connection->getDatabaseName();

        $constraints = $connection->select(
            "SELECT constraint_name, referenced_table_name
             FROM information_schema.key_column_usage
             WHERE table_schema = ?
               AND table_name = 'attendance_records'
               AND column_name = 'student_id'
               AND constraint_name IS NOT NULL
               AND referenced_table_name IS NOT NULL",
            [$databaseName]
        );

        foreach ($constraints as $constraint) {
            $name = $constraint->constraint_name;
            Schema::table('attendance_records', function (Blueprint $table) use ($name) {
                $table->dropForeign($name);
            });
        }

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreign('student_id')
                ->references('id')
                ->on('students')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance_records')) {
            return;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }
        $databaseName = $connection->getDatabaseName();

        $constraints = $connection->select(
            "SELECT constraint_name
             FROM information_schema.key_column_usage
             WHERE table_schema = ?
               AND table_name = 'attendance_records'
               AND column_name = 'student_id'
               AND constraint_name IS NOT NULL",
            [$databaseName]
        );

        foreach ($constraints as $constraint) {
            $name = $constraint->constraint_name;
            Schema::table('attendance_records', function (Blueprint $table) use ($name) {
                $table->dropForeign($name);
            });
        }

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreign('student_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};
