<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('class_location_calendars', 'calendar_norm')) {
            Schema::table('class_location_calendars', function (Blueprint $table) {
                $table->string('calendar_norm')->nullable()->after('calendar_slug');
                $table->index(['class_location_id', 'calendar_norm'], 'class_location_calendars_location_norm_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('class_location_calendars', 'calendar_norm')) {
            Schema::table('class_location_calendars', function (Blueprint $table) {
                $table->dropIndex('class_location_calendars_location_norm_idx');
                $table->dropColumn('calendar_norm');
            });
        }
    }
};
