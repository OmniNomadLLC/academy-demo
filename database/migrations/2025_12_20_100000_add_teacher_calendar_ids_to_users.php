<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'teacher_calendar_ids')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('teacher_calendar_ids')->nullable()->after('acuity_calendar_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'teacher_calendar_ids')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('teacher_calendar_ids');
            });
        }
    }
};
