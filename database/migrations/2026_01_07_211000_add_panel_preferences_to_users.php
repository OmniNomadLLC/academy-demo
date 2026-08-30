<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'panel_preferences')) {
                $table->json('panel_preferences')->nullable()->after('teacher_calendar_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'panel_preferences')) {
                $table->dropColumn('panel_preferences');
            }
        });
    }
};
