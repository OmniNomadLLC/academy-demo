<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_location_calendars', function (Blueprint $table) {
            if (! Schema::hasColumn('class_location_calendars', 'calendar_norm')) {
                $table->string('calendar_norm')->nullable()->after('calendar_slug');
                $table->index('calendar_norm', 'class_location_calendars_calendar_norm_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_location_calendars', function (Blueprint $table) {
            if (Schema::hasColumn('class_location_calendars', 'calendar_norm')) {
                $table->dropIndex('class_location_calendars_calendar_norm_idx');
                $table->dropColumn('calendar_norm');
            }
        });
    }
};
