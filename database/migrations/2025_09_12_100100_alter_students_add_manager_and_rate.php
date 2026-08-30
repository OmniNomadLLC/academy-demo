<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'manager_id')) {
                $table->unsignedBigInteger('manager_id')->nullable()->after('phone');
                $table->foreign('manager_id')->references('id')->on('managers')->nullOnDelete();
                $table->index('manager_id');
            }
            if (!Schema::hasColumn('students', 'attendance_rate')) {
                $table->decimal('attendance_rate', 5, 2)->default(0)->after('is_active');
            }
            if (!Schema::hasColumn('students', 'flagged_low_attendance_at')) {
                $table->timestamp('flagged_low_attendance_at')->nullable()->after('attendance_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'flagged_low_attendance_at')) {
                $table->dropColumn('flagged_low_attendance_at');
            }
            if (Schema::hasColumn('students', 'attendance_rate')) {
                $table->dropColumn('attendance_rate');
            }
            if (Schema::hasColumn('students', 'manager_id')) {
                $table->dropForeign(['manager_id']);
                $table->dropIndex(['manager_id']);
                $table->dropColumn('manager_id');
            }
        });
    }
};

